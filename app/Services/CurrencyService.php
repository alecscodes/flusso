<?php

namespace App\Services;

use App\Models\CurrencyExchangeRate;
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

        // Check cache first
        $cachedRate = CurrencyExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('date', $date)
            ->first();

        if ($cachedRate && $cachedRate->updated_at->isToday()) {
            return (float) $cachedRate->rate;
        }

        // Fetch from API
        $rate = $this->fetchRate($fromCurrency, $toCurrency, $date);

        if ($rate === null) {
            // If fetch failed and we have cached rate (even if old), return it
            if ($cachedRate) {
                return (float) $cachedRate->rate;
            }

            return null;
        }

        // Cache the rate
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
}
