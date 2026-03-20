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
     * Request-level cache for primary currency
     */
    private ?string $primaryCurrencyCache = null;

    /**
     * Get exchange rate from base currency to target currency
     * Uses cached rate if available and fresh, otherwise fetches from API
     */
    public function getRate(string $fromCurrency, string $toCurrency, ?string $date = null): ?float
    {
        // Normalize currencies to lowercase
        $fromCurrency = strtolower($fromCurrency);
        $toCurrency = strtolower($toCurrency);

        // If same currency, return 1
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        // Use today's date if not specified
        $date = $date ?? now()->format('Y-m-d');

        // Create cache key
        $cacheKey = "currency_rate_{$fromCurrency}_{$toCurrency}_{$date}";

        // Check Laravel cache first
        $cachedRate = Cache::get($cacheKey);
        if ($cachedRate !== null) {
            return $cachedRate;
        }

        // Check database cache
        $dbRate = CurrencyExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('date', $date)
            ->first();

        if ($dbRate) {
            $rate = (float) $dbRate->rate;
            Cache::put($cacheKey, $rate, self::CACHE_TTL * 60); // Convert minutes to seconds

            return $rate;
        }

        // Fetch from API
        $rate = $this->fetchRate($fromCurrency, $toCurrency, $date);

        if ($rate === null) {
            return null;
        }

        // Cache the rate in both Laravel cache and database
        Cache::put($cacheKey, $rate, self::CACHE_TTL * 60);

        try {
            CurrencyExchangeRate::updateOrCreate(
                [
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'date' => $date,
                ],
                [
                    'rate' => $rate,
                ]
            );
        } catch (UniqueConstraintViolationException $e) {
            // Handle race condition - another process already inserted this rate
            Log::debug('Currency rate already exists, handling race condition', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $date,
            ]);
        }

        return $rate;
    }

    /**
     * Fetch exchange rate from API
     */
    private function fetchRate(string $fromCurrency, string $toCurrency, string $date): ?float
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
                'date' => $date,
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
    public function convert(float $amount, string $fromCurrency, string $toCurrency, ?string $date = null): ?float
    {
        $rate = $this->getRate($fromCurrency, $toCurrency, $date);

        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }

    /**
     * Get the primary currency for a user
     * Prefers EUR, otherwise uses the most common currency among user's accounts
     * Uses request-level caching for performance
     */
    public function getPrimaryCurrency(User $user): string
    {
        // Use request-level cache if available
        if ($this->primaryCurrencyCache !== null) {
            return $this->primaryCurrencyCache;
        }

        // Check if user has EUR account first
        $eurAccount = $user->accounts()->where('currency', 'EUR')->first();
        if ($eurAccount) {
            $this->primaryCurrencyCache = 'EUR';

            return 'EUR';
        }

        // If no EUR account, use the currency of the account with the highest balance
        $accountWithHighestBalance = $user->accounts()
            ->orderByDesc('balance')
            ->first();

        $primaryCurrency = $accountWithHighestBalance?->currency ?? 'EUR';
        $this->primaryCurrencyCache = $primaryCurrency;

        return $primaryCurrency;
    }

    /**
     * Convert amount to user's primary currency
     */
    public function convertToPrimaryCurrency(float $amount, string $fromCurrency, User $user, ?string $date = null): ?float
    {
        $primaryCurrency = $this->getPrimaryCurrency($user);

        return $this->convert($amount, $fromCurrency, $primaryCurrency, $date);
    }

    /**
     * Convert a collection of amounts with different currencies to primary currency
     */
    public function convertCollectionToPrimaryCurrency(Collection $items, string $amountField, string $currencyField, User $user, ?string $date = null): Collection
    {
        $primaryCurrency = $this->getPrimaryCurrency($user);

        return $items->map(function ($item) use ($amountField, $currencyField, $primaryCurrency, $date) {
            $amount = $item->{$amountField};
            $currency = $item->{$currencyField};

            if ($currency === $primaryCurrency) {
                return $amount;
            }

            return $this->convert($amount, $currency, $primaryCurrency, $date) ?? 0;
        });
    }

    /**
     * Sum amounts in different currencies by converting to primary currency first
     */
    public function sumInPrimaryCurrency(Collection $items, string $amountField, string $currencyField, User $user, ?string $date = null): float
    {
        return $this->convertCollectionToPrimaryCurrency($items, $amountField, $currencyField, $user, $date)->sum();
    }
}
