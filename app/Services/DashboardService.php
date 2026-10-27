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
     */
    public function getDashboardData(User $user): array
    {
        $period = $user->getFinancialPeriod();
        $startDate = $period['start'];
        $endDate = $period['end'];

        $balances = $this->accountService->getAccountSummary($user);
        $summary = $this->transactionService->getTransactionSummaryForPeriod($user, $startDate, $endDate);
        $categorySpending = $this->transactionService->getCategorySpendingForPeriod($user, $startDate, $endDate);

        return [
            'accounts' => $balances['accounts'],
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'summary' => $summary,
            'upcomingPayments' => $this->paymentService->getUpcomingPayments($user, $endDate),
            'overduePayments' => $this->paymentService->getOverduePayments($user),
            'categorySpending' => $categorySpending,
            'paymentSummary' => $this->paymentService->getPaymentSummaryForPeriod($user, $startDate, $endDate),
            'availableBalance' => $balances['availableBalance'],
            'totalBalance' => $balances['totalBalance'],
            'savingsBalance' => $balances['savingsBalance'],
        ];
    }
}
