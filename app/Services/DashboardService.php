<?php

namespace App\Services;

use App\Models\User;

class DashboardService
{
    public function __construct(
        private TransactionService $transactionService,
        private PaymentService $paymentService,
        private AccountService $accountService,
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
        ];
    }
}
