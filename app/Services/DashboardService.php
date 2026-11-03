<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\LiquidityAlert;

class DashboardService
{
    public function __construct(
        private TransactionService $transactionService,
        private PaymentService $paymentService,
        private AccountService $accountService,
        private LiquidityService $liquidityService,
    ) {}

    /**
     * Get comprehensive dashboard data for a user.
     *
     * @return array<string, mixed>
     */
    public function getDashboardData(User $user): array
    {
        $period = $user->getFinancialPeriod();
        $startDate = $period['start'];
        $endDate = $period['end'];

        $balances = $this->accountService->getAccountSummary($user);
        $upcoming = $this->paymentService->getUpcomingPayments($user, $endDate);
        $overdue = $this->paymentService->getOverduePayments($user);

        return [
            'accounts' => $balances['accounts'],
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'summary' => $this->transactionService->getTransactionSummaryForPeriod($user, $startDate, $endDate),
            'upcomingPayments' => $upcoming,
            'overduePayments' => $overdue,
            'categorySpending' => $this->transactionService->getCategorySpendingForPeriod($user, $startDate, $endDate),
            'paymentSummary' => [
                'total_due' => $this->paymentService->totalDue($user, $overdue->concat($upcoming)),
                'primary_currency' => $balances['primaryCurrency'],
            ],
            'availableBalance' => $balances['availableBalance'],
            'totalBalance' => $balances['totalBalance'],
            'savingsBalance' => $balances['savingsBalance'],
            'liquidity' => $this->getLiquidityData($user),
        ];
    }

    /**
     * The "Heads up" section: accounts at risk in the next 30 days (each with its sentence)
     * and the unread alerts.
     *
     * @return array{at_risk: list<array<string, mixed>>, alerts: list<array{id: string, message: string, created_at: string}>, days: int}
     */
    private function getLiquidityData(User $user): array
    {
        return [
            'at_risk' => $this->liquidityService->atRisk($user)
                ->map(fn (array $row) => [...$row, 'message' => $this->liquidityService->describe($row)])
                ->all(),
            'alerts' => $user->unreadNotifications()
                ->where('type', LiquidityAlert::class)
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn ($notification) => [
                    'id' => $notification->id,
                    'message' => (string) ($notification->data['message'] ?? ''),
                    'created_at' => $notification->created_at->toDateString(),
                ])
                ->all(),
            'days' => LiquidityService::DEFAULT_DAYS,
        ];
    }
}
