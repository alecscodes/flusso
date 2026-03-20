<?php

namespace App\Services;

use App\Enums\PaymentType;
use App\Models\User;
use Carbon\Carbon;
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
    public function getUpcomingPaymentsForPeriod(User $user, Carbon $startDate, Carbon $endDate): Collection
    {
        return $user->payments()
            ->unpaid()
            ->whereBetween('due_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with(['account', 'category', 'recurringPayment'])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Get comprehensive financial overview with currency conversion.
     */
    public function getFinancialOverview(User $user): array
    {
        $period = $user->getFinancialPeriod();
        $startDate = $period['start'];
        $endDate = $period['end'];

        $summary = $this->transactionService->getTransactionSummaryForPeriod($user, $startDate, $endDate);
        $availableBalance = $this->accountService->getTotalBalanceInPrimaryCurrency($user);
        $totalBalance = $this->accountService->getFullBalanceInPrimaryCurrency($user);
        $accountsByCurrency = $this->accountService->getAccountsByCurrency($user);

        $overduePayments = $this->paymentService->getOverduePayments($user);
        $upcomingPayments = $this->paymentService->getUpcomingPayments($user, $endDate);

        $projectedExpensePayments = $upcomingPayments->where('type', PaymentType::Expense);
        $projectedIncomePayments = $upcomingPayments->where('type', PaymentType::Income);

        $projectedExpenses = $this->currencyService->sumInPrimaryCurrency($projectedExpensePayments, 'amount', 'currency', $user);
        $projectedIncome = $this->currencyService->sumInPrimaryCurrency($projectedIncomePayments, 'amount', 'currency', $user);
        $overdueAmount = $this->currencyService->sumInPrimaryCurrency($overduePayments, 'amount', 'currency', $user);

        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'current' => [
                'income' => $summary['income'],
                'expenses' => $summary['expenses'],
                'net' => $summary['net'],
            ],
            'projected' => [
                'expenses' => $projectedExpenses,
                'income' => $projectedIncome,
                'net' => $projectedIncome - $projectedExpenses,
            ],
            'balances' => [
                'available' => $availableBalance,
                'total' => $totalBalance,
                'primary_currency' => $this->currencyService->getPrimaryCurrency($user),
                'by_currency' => $accountsByCurrency->map(function ($accounts) use ($user) {
                    $primaryCurrency = $this->currencyService->getPrimaryCurrency($user);
                    $totalInPrimaryCurrency = $this->currencyService->sumInPrimaryCurrency($accounts, 'balance', 'currency', $user);

                    return [
                        'currency' => $accounts->first()->currency,
                        'total' => $totalInPrimaryCurrency,
                        'accounts' => $accounts->count(),
                    ];
                })->values(),
            ],
            'alerts' => [
                'overdue_count' => $overduePayments->count(),
                'overdue_amount' => $overdueAmount,
            ],
        ];
    }

    /**
     * Get monthly trend data for the specified number of months.
     */
    public function getMonthlyTrend(User $user, int $months = 6): Collection
    {
        $trends = collect();

        for ($i = $months - 1; $i >= 0; $i--) {
            $startDate = now()->subMonths($i)->startOfMonth();
            $endDate = now()->subMonths($i)->endOfMonth();

            $summary = $this->transactionService->getTransactionSummaryForPeriod($user, $startDate, $endDate);

            $trends->push([
                'month' => $startDate->format('Y-m'),
                'month_name' => $startDate->format('M Y'),
                'income' => $summary['income'],
                'expenses' => $summary['expenses'],
                'net' => $summary['net'],
            ]);
        }

        return $trends;
    }

    /**
     * Get upcoming payments in a calendar format.
     */
    public function getUpcomingPaymentsCalendar(User $user, int $daysAhead = 30): Collection
    {
        $endDate = now()->addDays($daysAhead);

        $this->paymentService->generateAllPaymentsForUser($user, $endDate);

        return $user->payments()
            ->unpaid()
            ->whereBetween('due_date', [now()->toDateString(), $endDate->toDateString()])
            ->with(['account', 'category', 'recurringPayment'])
            ->orderBy('due_date')
            ->get()
            ->groupBy(fn ($payment) => $payment->due_date->toDateString());
    }
}
