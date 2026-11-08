<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The period summary ("Insights"): plain numbers and short sentences, all computed
 * here from SQL and the existing services (the dashboard's totals, the category
 * spending, the payments, the people balances and the liquidity forecast). Nothing
 * in it comes from a model.
 *
 * @phpstan-type Change array{amount: float, percent: float|null}
 * @phpstan-type PeriodRange array{start: CarbonImmutable, end: CarbonImmutable}
 * @phpstan-type CategorySpend array{name: string, color: string|null, total: float}
 * @phpstan-type TopCategory array{category_id: int, name: string, color: string|null, total: float, previous_total: float, change_amount: float, change_percent: float|null}
 * @phpstan-type UnusualCategory array{category_id: int, name: string, total: float, average: float, ratio: float}
 * @phpstan-type BiggestExpense array{id: int, date: string, description: string|null, category: string|null, amount: float, currency: string, amount_primary: float}
 * @phpstan-type PaymentLine array{id: int, name: string, due_date: string, amount: float, currency: string, type: string}
 * @phpstan-type PersonBalance array{name: string, currency: string, balance: float}
 * @phpstan-type Insights array{
 *     period: array{start: string, end: string, is_current: bool},
 *     previous_period: array{start: string, end: string},
 *     navigation: array{previous: string, next: string|null},
 *     currency: string,
 *     is_estimate: bool,
 *     summary: array{income: float, expenses: float, net: float, transaction_count: int},
 *     previous_summary: array{income: float, expenses: float, net: float, transaction_count: int},
 *     expense_change: Change,
 *     top_categories: list<TopCategory>,
 *     unusual_categories: list<UnusualCategory>,
 *     biggest_expenses: list<BiggestExpense>,
 *     payments: array{days: int, overdue: list<PaymentLine>, upcoming: list<PaymentLine>, overdue_total: float, upcoming_total: float},
 *     people: list<PersonBalance>,
 *     liquidity: string|null,
 *     sentence: string
 * }
 */
class InsightsService
{
    /** A category is unusual when it reaches this multiple of its average over the previous periods... */
    public const UNUSUAL_RATIO = 1.5;

    /** ...and at least this amount (primary currency), so tiny categories never trigger. */
    public const UNUSUAL_MIN_AMOUNT = 50.0;

    public const HISTORY_PERIODS = 3;

    public const UPCOMING_DAYS = 30;

    private const TOP_CATEGORIES = 5;

    private const BIGGEST_EXPENSES = 5;

    private const LISTED_PAYMENTS = 10;

    public function __construct(
        private TransactionService $transactionService,
        private PaymentService $paymentService,
        private CounterpartyService $counterpartyService,
        private LiquidityService $liquidityService,
        private CurrencyService $currencyService,
    ) {}

    /**
     * The summary of the financial period that contains `$anchor` (today when null).
     *
     * @return Insights
     */
    public function forPeriod(User $user, ?CarbonInterface $anchor = null): array
    {
        $figures = $this->figures($user, $anchor);
        $period = $figures['range'];
        $previous = $figures['previous_range'];

        $spending = $this->spendingByCategory($user, $period);
        $previousSpending = $this->spendingByCategory($user, $previous);
        $topCategories = $this->topCategories($spending, $previousSpending);

        return [
            'period' => $figures['period'],
            'previous_period' => [
                'start' => $previous['start']->toDateString(),
                'end' => $previous['end']->toDateString(),
            ],
            'navigation' => [
                'previous' => $previous['start']->toDateString(),
                'next' => $period['end']->endOfDay()->lt(now()) ? $period['end']->addDay()->toDateString() : null,
            ],
            'currency' => $figures['currency'],
            'is_estimate' => $figures['is_estimate'],
            'summary' => $figures['summary'],
            'previous_summary' => $figures['previous_summary'],
            'expense_change' => $figures['expense_change'],
            'top_categories' => $topCategories,
            'unusual_categories' => $this->unusualCategories($spending, $this->history($user, $previous, $previousSpending)),
            'biggest_expenses' => $this->biggestExpenses($user, $period, $figures['currency']),
            'payments' => $this->payments($user),
            'people' => $this->people($user),
            'liquidity' => $this->liquidityMention($user),
            'sentence' => $this->sentence($figures['summary']['expenses'], $figures['expense_change'], $figures['currency'], $topCategories[0]['name'] ?? null),
        ];
    }

    /**
     * The compact version for the dashboard card: the same numbers and sentence as
     * forPeriod(), without the history, payments, people and liquidity queries.
     *
     * @return array{period: array{start: string, end: string, is_current: bool}, currency: string, is_estimate: bool, summary: array{income: float, expenses: float, net: float, transaction_count: int}, expense_change: Change, sentence: string}
     */
    public function cardFor(User $user): array
    {
        $figures = $this->figures($user);
        $topCategories = $this->topCategories($this->spendingByCategory($user, $figures['range']), []);

        return [
            'period' => $figures['period'],
            'currency' => $figures['currency'],
            'is_estimate' => $figures['is_estimate'],
            'summary' => $figures['summary'],
            'expense_change' => $figures['expense_change'],
            'sentence' => $this->sentence($figures['summary']['expenses'], $figures['expense_change'], $figures['currency'], $topCategories[0]['name'] ?? null),
        ];
    }

    /**
     * What the card and the full page share: the period and the one before it, the primary
     * currency and both summaries.
     *
     * @return array{range: PeriodRange, previous_range: PeriodRange, period: array{start: string, end: string, is_current: bool}, currency: string, is_estimate: bool, summary: array{income: float, expenses: float, net: float, transaction_count: int}, previous_summary: array{income: float, expenses: float, net: float, transaction_count: int}, expense_change: Change}
     */
    private function figures(User $user, ?CarbonInterface $anchor = null): array
    {
        $range = $user->getFinancialPeriod($anchor);
        $previousRange = $user->getFinancialPeriod($range['start']->subDay());
        $currency = $this->currencyService->getPrimaryCurrency($user);

        $summary = $this->transactionService->getTransactionSummaryForPeriod($user, $range['start'], $range['end']);
        $previousSummary = $this->transactionService->getTransactionSummaryForPeriod($user, $previousRange['start'], $previousRange['end']);

        return [
            'range' => $range,
            'previous_range' => $previousRange,
            'period' => [
                'start' => $range['start']->toDateString(),
                'end' => $range['end']->toDateString(),
                'is_current' => now()->betweenIncluded($range['start'], $range['end']),
            ],
            'currency' => $currency,
            'is_estimate' => $this->hasForeignCurrency($user, $range, $currency),
            'summary' => $summary,
            'previous_summary' => $previousSummary,
            'expense_change' => $this->change($summary['expenses'], $previousSummary['expenses']),
        ];
    }

    /**
     * The deterministic one-sentence summary, e.g. "You spent 1,200.00 EUR, 12% more than last period, mostly on Groceries."
     *
     * @param  Change  $change
     */
    private function sentence(float $expenses, array $change, string $currency, ?string $topCategory): string
    {
        if ($expenses <= 0) {
            return 'No expenses were recorded in this period.';
        }

        $sentence = 'You spent '.number_format($expenses, 2).' '.$currency;
        $percent = $change['percent'];

        if ($percent !== null && abs($percent) >= 0.1) {
            $sentence .= ', '.rtrim(rtrim(number_format(abs($percent), 1), '0'), '.').'% '.($percent > 0 ? 'more' : 'less').' than last period';
        }

        if ($topCategory !== null) {
            $sentence .= ', mostly on '.$topCategory;
        }

        return $sentence.'.';
    }

    /**
     * Expense totals per category (primary currency), biggest first, keyed by category id.
     *
     * @param  PeriodRange  $period
     * @return array<int, CategorySpend>
     */
    private function spendingByCategory(User $user, array $period): array
    {
        $spending = [];

        foreach ($this->transactionService->getCategorySpendingForPeriod($user, $period['start'], $period['end'], PHP_INT_MAX) as $row) {
            $category = $row['category'];

            if ($category === null) {
                continue;
            }

            $color = $category->color;

            $spending[$category->id] = [
                'name' => (string) $category->name,
                'color' => is_string($color) ? $color : null,
                'total' => $row['total'],
            ];
        }

        return $spending;
    }

    /**
     * The category spending of the periods before the current one, newest first.
     *
     * @param  PeriodRange  $previous  The period right before the current one.
     * @param  array<int, CategorySpend>  $previousSpending  Its spending, already loaded.
     * @return list<array<int, CategorySpend>>
     */
    private function history(User $user, array $previous, array $previousSpending): array
    {
        $history = [$previousSpending];
        $period = $previous;

        for ($i = 1; $i < self::HISTORY_PERIODS; $i++) {
            $period = $user->getFinancialPeriod($period['start']->subDay());
            $history[] = $this->spendingByCategory($user, $period);
        }

        return $history;
    }

    /**
     * @param  array<int, CategorySpend>  $spending
     * @param  array<int, CategorySpend>  $previousSpending
     * @return list<TopCategory>
     */
    private function topCategories(array $spending, array $previousSpending): array
    {
        $top = [];

        foreach (array_slice($spending, 0, self::TOP_CATEGORIES, true) as $categoryId => $row) {
            $previousTotal = $previousSpending[$categoryId]['total'] ?? 0.0;
            $change = $this->change($row['total'], $previousTotal);

            $top[] = [
                'category_id' => $categoryId,
                'name' => $row['name'],
                'color' => $row['color'],
                'total' => round($row['total'], 2),
                'previous_total' => round($previousTotal, 2),
                'change_amount' => $change['amount'],
                'change_percent' => $change['percent'],
            ];
        }

        return $top;
    }

    /**
     * Categories at least UNUSUAL_RATIO times their average of the previous periods (zero-spend
     * periods count), and at least UNUSUAL_MIN_AMOUNT. A category with no history is new, not unusual.
     *
     * @param  array<int, CategorySpend>  $spending
     * @param  list<array<int, CategorySpend>>  $history
     * @return list<UnusualCategory>
     */
    private function unusualCategories(array $spending, array $history): array
    {
        $unusual = [];

        foreach ($spending as $categoryId => $row) {
            $average = array_sum(array_map(fn (array $period): float => $period[$categoryId]['total'] ?? 0.0, $history)) / count($history);

            if ($average <= 0 || $row['total'] < self::UNUSUAL_MIN_AMOUNT || $row['total'] < self::UNUSUAL_RATIO * $average) {
                continue;
            }

            $unusual[] = [
                'category_id' => $categoryId,
                'name' => $row['name'],
                'total' => round($row['total'], 2),
                'average' => round($average, 2),
                'ratio' => round($row['total'] / $average, 1),
            ];
        }

        usort($unusual, fn (array $a, array $b): int => $b['ratio'] <=> $a['ratio']);

        return $unusual;
    }

    /**
     * The biggest single expenses, ranked in the primary currency. Each currency is
     * limited in SQL first, so only a handful of rows is ever loaded.
     *
     * @param  PeriodRange  $period
     * @return list<BiggestExpense>
     */
    private function biggestExpenses(User $user, array $period, string $primaryCurrency): array
    {
        $expenses = fn () => $user->transactions()
            ->inPeriod($period['start'], $period['end'])
            ->where('type', TransactionType::Expense);

        $rows = [];

        foreach ($expenses()->distinct()->pluck('currency') as $currency) {
            $biggest = $expenses()
                ->where('currency', $currency)
                ->with(['category' => fn ($query) => $query->withTrashed()])
                ->orderByDesc('amount')
                ->orderByDesc('id')
                ->limit(self::BIGGEST_EXPENSES)
                ->get();

            foreach ($biggest as $transaction) {
                $amount = (float) $transaction->amount;

                $rows[] = [
                    'id' => $transaction->id,
                    'date' => $transaction->date->toDateString(),
                    'description' => $transaction->description,
                    'category' => $transaction->category?->name,
                    'amount' => $amount,
                    'currency' => (string) $currency,
                    'amount_primary' => round($currency === $primaryCurrency
                        ? $amount
                        : ($this->currencyService->convert($amount, (string) $currency, $primaryCurrency) ?? 0.0), 2),
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['amount_primary'], $b['id']] <=> [$a['amount_primary'], $a['id']]);

        return array_slice($rows, 0, self::BIGGEST_EXPENSES);
    }

    /**
     * Whether a currency other than the primary one is in the period's income or expenses,
     * which makes the totals an estimate at today's rates.
     *
     * @param  PeriodRange  $period
     */
    private function hasForeignCurrency(User $user, array $period, string $primaryCurrency): bool
    {
        return $user->transactions()
            ->inPeriod($period['start'], $period['end'])
            ->whereIn('type', [TransactionType::Income, TransactionType::Expense])
            ->where('currency', '!=', $primaryCurrency)
            ->exists();
    }

    /**
     * Overdue payments and the ones due in the next days, with what is still to pay.
     *
     * @return array{days: int, overdue: list<PaymentLine>, upcoming: list<PaymentLine>, overdue_total: float, upcoming_total: float}
     */
    private function payments(User $user): array
    {
        $overdue = $this->paymentService->getOverduePayments($user);
        $upcoming = $this->paymentService->getUpcomingPayments($user, now()->addDays(self::UPCOMING_DAYS));

        return [
            'days' => self::UPCOMING_DAYS,
            'overdue' => $overdue->take(self::LISTED_PAYMENTS)->map(fn (Payment $payment): array => $this->paymentLine($payment))->values()->all(),
            'upcoming' => $upcoming->take(self::LISTED_PAYMENTS)->map(fn (Payment $payment): array => $this->paymentLine($payment))->values()->all(),
            'overdue_total' => round($this->paymentService->totalDue($user, $overdue), 2),
            'upcoming_total' => round($this->paymentService->totalDue($user, $upcoming), 2),
        ];
    }

    /**
     * @return PaymentLine
     */
    private function paymentLine(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'name' => $payment->recurringPayment->name,
            'due_date' => $payment->due_date->toDateString(),
            'amount' => (float) $payment->amount,
            'currency' => (string) $payment->currency,
            'type' => $payment->type->value,
        ];
    }

    /**
     * What is still open with people: positive means they owe the owner, negative the other way round.
     *
     * @return list<PersonBalance>
     */
    private function people(User $user): array
    {
        $people = [];

        foreach ($this->counterpartyService->balances($user) as $counterparty) {
            foreach ($counterparty['balances'] as $balance) {
                if (abs($balance['balance']) >= 0.01) {
                    $people[] = ['name' => $counterparty['name'], 'currency' => $balance['currency'], 'balance' => $balance['balance']];
                }
            }
        }

        return $people;
    }

    /**
     * One line about the accounts at risk in the next days, or null when none is.
     */
    private function liquidityMention(User $user): ?string
    {
        $atRisk = $this->liquidityService->atRisk($user);

        $first = $atRisk->first();

        if ($first === null) {
            return null;
        }

        $line = $this->liquidityService->describe($first);
        $others = $atRisk->count() - 1;

        return $others > 0 ? "{$line} (and {$others} more)" : $line;
    }

    /**
     * @return Change
     */
    private function change(float $current, float $previous): array
    {
        return [
            'amount' => round($current - $previous, 2),
            'percent' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
        ];
    }
}
