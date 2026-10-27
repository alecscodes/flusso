<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(
        private TransactionService $transactionService,
        private PaymentService $paymentService,
        private AccountService $accountService,
        private CurrencyService $currencyService
    ) {}

    /**
     * Get comprehensive dashboard data for a user.
     */
    public function getDashboardData(User $user): array
    {
        $period = $user->getFinancialPeriod();
        $startDate = $period['start'];
        $endDate = $period['end'];

        $this->paymentService->generateAllPaymentsForUser($user);

        $accounts = $this->accountService->getAccountsForUser($user);
        $summary = $this->transactionService->getTransactionSummaryForPeriod($user, $startDate, $endDate);
        $categorySpending = $this->transactionService->getCategorySpendingForPeriod($user, $startDate, $endDate);

        $upcomingPayments = $this->getUpcomingPaymentsForPeriod($user, $startDate, $endDate);
        $overduePayments = $this->paymentService->getOverduePayments($user);

        $paymentSummary = $this->paymentService->getPaymentSummaryForPeriod($user, $startDate, $endDate);
        $availableBalance = $this->accountService->getTotalBalanceInPrimaryCurrency($user);
        $totalBalance = $this->accountService->getFullBalanceInPrimaryCurrency($user);
        $savingsBalance = $this->accountService->getSavingsBalanceInPrimaryCurrency($user);
        $paymentSummary['balance_after_planned'] = $availableBalance - $paymentSummary['total_due'];

        return [
            'accounts' => $accounts,
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'summary' => $summary,
            'upcomingPayments' => $upcomingPayments,
            'overduePayments' => $overduePayments,
            'categorySpending' => $categorySpending,
            'paymentSummary' => $paymentSummary,
            'availableBalance' => $availableBalance,
            'totalBalance' => $totalBalance,
            'savingsBalance' => $savingsBalance,
        ];
    }

    /**
     * Get upcoming payments for a specific period.
     */
    public function getUpcomingPaymentsForPeriod(User $user, CarbonInterface $startDate, CarbonInterface $endDate): Collection
    {
        return $user->payments()
            ->unpaid()
            ->whereBetween('due_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with(['account', 'category', 'recurringPayment'])
            ->orderBy('due_date')
            ->get();
    }
}
