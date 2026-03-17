<?php

namespace App\Services;

use Carbon\Carbon;

class FinancialPeriodService
{
    /**
     * Get the current financial period start and end dates based on reset_date
     */
    public function getCurrentPeriod(?int $resetDate): array
    {
        if ($resetDate === null) {
            // Default to calendar month if no reset date is set
            return [
                'start' => now()->startOfMonth(),
                'end' => now()->endOfMonth(),
            ];
        }

        $today = now();
        $currentDay = $today->day;

        if ($currentDay >= $resetDate) {
            // Current period started this month
            $start = $today->copy()->day($resetDate)->startOfDay();
            $end = $today->copy()->addMonth()->day($resetDate)->subDay()->endOfDay();
        } else {
            // Current period started last month
            $start = $today->copy()->subMonth()->day($resetDate)->startOfDay();
            $end = $today->copy()->day($resetDate)->subDay()->endOfDay();
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Get the next financial period start and end dates
     */
    public function getNextPeriod(?int $resetDate): array
    {
        if ($resetDate === null) {
            return [
                'start' => now()->addMonth()->startOfMonth(),
                'end' => now()->addMonth()->endOfMonth(),
            ];
        }

        $currentPeriod = $this->getCurrentPeriod($resetDate);
        $nextStart = $currentPeriod['end']->copy()->addDay()->startOfDay();
        $nextEnd = $nextStart->copy()->addMonth()->day($resetDate)->subDay()->endOfDay();

        return [
            'start' => $nextStart,
            'end' => $nextEnd,
        ];
    }

    /**
     * Check if a date falls within the current period
     */
    public function isInCurrentPeriod(Carbon $date, ?int $resetDate): bool
    {
        $period = $this->getCurrentPeriod($resetDate);

        return $date->gte($period['start']) && $date->lte($period['end']);
    }
}
