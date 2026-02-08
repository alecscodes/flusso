<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(
        private TransactionService $transactionService,
        private PaymentService $paymentService,
        private AccountService $accountService
    ) {}

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
        $totalBalance = $this->accountService->getTotalBalance($user);
        $paymentSummary['balance_after_planned'] = $totalBalance - $paymentSummary['total_due'];

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
        ];
    }

    public function getUpcomingPaymentsForPeriod(User $user, Carbon $startDate, Carbon $endDate): Collection
    {
        return $user->payments()
            ->unpaid()
            ->whereBetween('due_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with(['account', 'category', 'recurringPayment'])
            ->orderBy('due_date')
            ->get();
    }

    public function getFinancialOverview(User $user): array
    {
        $period = $user->getFinancialPeriod();
        $startDate = $period['start'];
        $endDate = $period['end'];

        $summary = $this->transactionService->getTransactionSummaryForPeriod($user, $startDate, $endDate);

        $totalBalance = $this->accountService->getTotalBalance($user);
        $accountsByCurrency = $this->accountService->getAccountsByCurrency($user);

        $overduePayments = $this->paymentService->getOverduePayments($user);
        $upcomingPayments = $this->paymentService->getUpcomingPayments($user, $endDate);

        $projectedExpenses = $upcomingPayments
            ->where('type', \App\Enums\PaymentType::Expense)
            ->sum('amount');

        $projectedIncome = $upcomingPayments
            ->where('type', \App\Enums\PaymentType::Income)
            ->sum('amount');

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
                'expenses' => (float) $projectedExpenses,
                'income' => (float) $projectedIncome,
                'net' => (float) ($projectedIncome - $projectedExpenses),
            ],
            'balances' => [
                'total' => $totalBalance,
                'by_currency' => $accountsByCurrency->map(fn ($accounts) => [
                    'currency' => $accounts->first()->currency,
                    'total' => (float) $accounts->sum('balance'),
                    'accounts' => $accounts->count(),
                ])->values(),
            ],
            'alerts' => [
                'overdue_count' => $overduePayments->count(),
                'overdue_amount' => (float) $overduePayments->sum('amount'),
            ],
        ];
    }

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
