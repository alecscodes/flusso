<?php

namespace App\Services;

use App\Models\CurrencyExchangeRate;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyService
{
    /**
     * Primary API endpoint via jsDelivr CDN
     */
    private const PRIMARY_ENDPOINT = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/%s.min.json';

    /**
     * Fallback API endpoint via Cloudflare
     */
    private const FALLBACK_ENDPOINT = 'https://latest.currency-api.pages.dev/v1/currencies/%s.min.json';

    /**
     * Cache TTL in minutes
     */
    private const CACHE_TTL = 60; // 1 hour

    /**
     * How long (seconds) a failed lookup is remembered, so an outage costs one attempt, not one per conversion
     */
    private const FAILURE_TTL = 600;

    /**
     * What this request already resolved (the service is scoped), so a missing rate costs
     * one lookup and one warning rather than one per conversion.
     *
     * @var array<string, float|null>
     */
    private array $rates = [];

    /** @var array<string, true> */
    private array $warned = [];

    /** @var array<int, string> */
    private array $primaryCurrencies = [];

    /**
     * Exchange rate between two currencies: today's, from the cache, the database or the API.
     * When there is none, the latest stored rate stands in (display only: pass
     * `$allowStale = false` for anything that gets saved). Null when no rate is usable.
     */
    public function getRate(string $fromCurrency, string $toCurrency, bool $allowStale = true): ?float
    {
        $fromCurrency = strtolower($fromCurrency);
        $toCurrency = strtolower($toCurrency);

        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $key = "{$fromCurrency}_{$toCurrency}_".(int) $allowStale;

        if (array_key_exists($key, $this->rates)) {
            return $this->rates[$key];
        }

        $rate = $this->todaysRate($fromCurrency, $toCurrency)
            ?? ($allowStale ? $this->latestStoredRate($fromCurrency, $toCurrency) : null);

        if ($rate === null && ! isset($this->warned["{$fromCurrency}_{$toCurrency}"])) {
            $this->warned["{$fromCurrency}_{$toCurrency}"] = true;
            Log::warning('No exchange rate available', ['from' => $fromCurrency, 'to' => $toCurrency]);
        }

        return $this->rates[$key] = $rate;
    }

    private function todaysRate(string $fromCurrency, string $toCurrency): ?float
    {
        $date = now()->toDateString();
        $cacheKey = "currency_rate_{$fromCurrency}_{$toCurrency}_{$date}";

        $cachedRate = Cache::get($cacheKey);
        if ($cachedRate !== null) {
            return $cachedRate === false ? null : $cachedRate;
        }

        $dbRate = CurrencyExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('date', $date)
            ->value('rate');

        if ($dbRate !== null) {
            Cache::put($cacheKey, (float) $dbRate, self::CACHE_TTL * 60);

            return (float) $dbRate;
        }

        $rate = $this->fetchRate($fromCurrency, $toCurrency);

        if ($rate === null) {
            Cache::put($cacheKey, false, self::FAILURE_TTL);

            return null;
        }

        Cache::put($cacheKey, $rate, self::CACHE_TTL * 60);

        try {
            CurrencyExchangeRate::updateOrCreate(
                ['from_currency' => $fromCurrency, 'to_currency' => $toCurrency, 'date' => $date],
                ['rate' => $rate],
            );
        } catch (UniqueConstraintViolationException) {
            // Another process stored it first.
        }

        return $rate;
    }

    private function latestStoredRate(string $fromCurrency, string $toCurrency): ?float
    {
        $rate = CurrencyExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->latest('date')
            ->value('rate');

        return $rate === null ? null : (float) $rate;
    }

    /**
     * Fetch exchange rate from API
     */
    private function fetchRate(string $fromCurrency, string $toCurrency): ?float
    {
        // Try primary endpoint first
        $response = $this->fetchFromEndpoint(sprintf(self::PRIMARY_ENDPOINT, $fromCurrency));

        if ($response === null) {
            // Try fallback endpoint
            $response = $this->fetchFromEndpoint(sprintf(self::FALLBACK_ENDPOINT, $fromCurrency));
        }

        if ($response === null) {
            Log::warning('Failed to fetch currency rate', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
            ]);

            return null;
        }

        // Extract rate from response
        // Response format: {"date":"2025-12-05","ron":{"eur":0.2,"usd":0.22,...}}
        $baseCurrency = $fromCurrency;
        if (! isset($response[$baseCurrency][$toCurrency])) {
            Log::warning('Currency rate not found in API response', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'response_keys' => array_keys($response[$baseCurrency] ?? []),
            ]);

            return null;
        }

        return (float) $response[$baseCurrency][$toCurrency];
    }

    /**
     * Fetch from a specific endpoint
     */
    /**
     * @return array<string, mixed>|null
     */
    private function fetchFromEndpoint(string $url): ?array
    {
        try {
            $response = Http::timeout(5)->get($url);

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Currency API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Convert amount from one currency to another
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): ?float
    {
        $rate = $this->getRate($fromCurrency, $toCurrency);

        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }

    /**
     * Get the primary currency for a user
     * EUR when the user has an EUR account, otherwise the currency of the account with the highest balance.
     */
    public function getPrimaryCurrency(User $user): string
    {
        return $this->primaryCurrencies[$user->id] ??= $user->accounts()->where('currency', 'EUR')->exists()
            ? 'EUR'
            : ($user->accounts()->orderByDesc('balance')->value('currency') ?? 'EUR');
    }

    /**
     * Convert amount to user's primary currency
     */
    public function convertToPrimaryCurrency(float $amount, string $fromCurrency, User $user): ?float
    {
        $primaryCurrency = $this->getPrimaryCurrency($user);

        return $this->convert($amount, $fromCurrency, $primaryCurrency);
    }

    /**
     * Convert a collection of amounts with different currencies to primary currency
     *
     * @template TItem of object
     *
     * @param  Collection<int, TItem>  $items
     * @return Collection<int, mixed>
     */
    public function convertCollectionToPrimaryCurrency(Collection $items, string $amountField, string $currencyField, User $user): Collection
    {
        $primaryCurrency = $this->getPrimaryCurrency($user);

        return $items->map(function ($item) use ($amountField, $currencyField, $primaryCurrency) {
            $amount = $item->{$amountField};
            $currency = $item->{$currencyField};

            if ($currency === $primaryCurrency) {
                return $amount;
            }

            return $this->convert($amount, $currency, $primaryCurrency) ?? 0;
        });
    }

    /**
     * Sum amounts in different currencies by converting to primary currency first
     *
     * @template TItem of object
     *
     * @param  Collection<int, TItem>  $items
     */
    public function sumInPrimaryCurrency(Collection $items, string $amountField, string $currencyField, User $user): float
    {
        return $this->convertCollectionToPrimaryCurrency($items, $amountField, $currencyField, $user)->sum();
    }
}
