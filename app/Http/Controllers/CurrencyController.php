<?php

namespace App\Http\Controllers;

use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function __construct(
        private CurrencyService $currencyService
    ) {}

    /**
     * Get exchange rate between two currencies
     */
    public function getRates(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'string', 'size:3'],
            'to' => ['required', 'string', 'size:3'],
            'date' => ['nullable', 'date'],
        ]);

        $fromCurrency = strtoupper($request->input('from'));
        $toCurrency = strtoupper($request->input('to'));
        $date = $request->input('date');

        $rate = $this->currencyService->getRate($fromCurrency, $toCurrency, $date);

        if ($rate === null) {
            return response()->json([
                'error' => 'Unable to fetch exchange rate',
            ], 404);
        }

        return response()->json([
            'from' => $fromCurrency,
            'to' => $toCurrency,
            'rate' => $rate,
            'date' => $date ?? now()->format('Y-m-d'),
        ]);
    }
}
