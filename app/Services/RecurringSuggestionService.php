<?php

namespace App\Services;

use App\Enums\SuggestionDismissalKind;
use App\Enums\TransactionType;
use App\Models\DismissedRecurringSuggestion;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DescriptionMatcher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Charges that look like a subscription, offered as suggestions for a planned payment. Built only
 * from the owner's own expense history, no AI, and nothing is ever created from here: many of his
 * services are tried on purpose and dropped again, so every suggestion can be dismissed for good
 * ("not recurring") or until a later day ("temporary", the owner is only trying it).
 * Amounts stay in the currency of the account they were booked on, nothing is converted.
 */
class RecurringSuggestionService
{
    /** How much history is looked at. */
    public const HISTORY_MONTHS = 13;

    /** A temporary dismissal hides a suggestion for this many months unless told otherwise. */
    public const DEFAULT_SNOOZE_MONTHS = 6;

    /** Amounts may differ by this share (of the smallest one) and still count as the same charge. */
    public const AMOUNT_TOLERANCE = 0.15;

    /** The latest charge is flagged as a price jump when it is at least this many times the previous one. */
    public const PRICE_JUMP_FACTOR = 3;

    /** A merchant whose name is this alike to a planned payment's name is already planned. */
    public const SAME_NAME_SIMILARITY = 0.8;

    /**
     * Gap in days between two charges that still counts for each interval, how many charges it
     * takes to call it a pattern, and how many days past its next expected charge a suggestion
     * stays up (afterwards the service is assumed to be cancelled).
     *
     * @var array<string, array{min_gap: int, max_gap: int, min_charges: int, grace_days: int}>
     */
    private const INTERVALS = [
        'weeks' => ['min_gap' => 6, 'max_gap' => 8, 'min_charges' => 3, 'grace_days' => 7],
        'months' => ['min_gap' => 28, 'max_gap' => 35, 'min_charges' => 2, 'grace_days' => 15],
        'years' => ['min_gap' => 350, 'max_gap' => 380, 'min_charges' => 2, 'grace_days' => 30],
    ];

    /**
     * @return Collection<int, array{key: string, label: string, interval_type: string, interval_value: int, amount: string, previous_amount: string, currency: string, account_id: int, category_id: int|null, occurrences: int, last_date: string, next_expected: string, price_jumped: bool}>
     */
    public function suggestionsFor(User $user, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $today = $now->toDateString();
        $currencies = $user->accounts()->pluck('currency', 'id');
        $categoryIds = $user->categories()->pluck('id')->all();
        $plannedKeys = $user->recurringPayments()->pluck('name')
            ->map(fn (string $name) => $this->merchantKey($name))
            ->filter()
            ->all();
        $dismissals = $user->dismissedRecurringSuggestions()->get()
            ->keyBy(fn (DismissedRecurringSuggestion $dismissal) => $this->canonicalKey($dismissal->normalized_key));

        $transactions = $user->transactions()
            ->where('type', TransactionType::Expense->value)
            ->whereNull('recurring_payment_id')
            ->whereNotNull('description')
            ->whereIn('account_id', $currencies->keys())
            ->where('date', '>=', $now->copy()->subMonths(self::HISTORY_MONTHS)->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'account_id', 'category_id', 'amount', 'description', 'date']);

        $rows = [];

        foreach ($transactions->groupBy(fn (Transaction $transaction) => $this->merchantKey($transaction->description).'|'.$transaction->account_id) as $merchantOnAccount) {
            /** @var Collection<int, Transaction> $group */
            $group = $merchantOnAccount->values();
            $key = $this->merchantKey($group->firstOrFail()->description);

            if ($key === '' || $this->isPlanned($key, $plannedKeys) || ($dismissals->get($key)?->hidesOn($today) ?? false)) {
                continue;
            }

            $row = $this->candidate($key, $group, $currencies->all(), $categoryIds, $today);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['next_expected'], $a['key']] <=> [$b['next_expected'], $b['key']]);

        return $this->suggestionRows($rows);
    }

    /**
     * Hides the merchant from the suggestions: for good, or (temporary) until `$snoozeUntil`.
     */
    public function dismiss(User $user, string $key, SuggestionDismissalKind $kind, ?string $snoozeUntil = null): DismissedRecurringSuggestion
    {
        return $user->dismissedRecurringSuggestions()->updateOrCreate(
            ['normalized_key' => $this->canonicalKey($key)],
            [
                'kind' => $kind,
                'snooze_until' => $kind === SuggestionDismissalKind::Temporary
                    ? ($snoozeUntil ?? now()->addMonthsNoOverflow(self::DEFAULT_SNOOZE_MONTHS)->toDateString())
                    : null,
            ],
        );
    }

    /**
     * Takes the merchant back into the suggestions.
     */
    public function restore(User $user, string $key): void
    {
        $canonical = $this->canonicalKey($key);

        $user->dismissedRecurringSuggestions()->get()
            ->filter(fn (DismissedRecurringSuggestion $dismissal) => $this->canonicalKey($dismissal->normalized_key) === $canonical)
            ->each->delete();
    }

    /**
     * What is hidden right now, to offer taking it back: "not recurring" ones and temporary ones
     * that have not ended yet, the soonest to come back first.
     *
     * @return Collection<int, array{key: string, label: string, kind: string, snooze_until: string|null}>
     */
    public function dismissedFor(User $user, ?CarbonInterface $now = null): Collection
    {
        $today = ($now ?? now())->toDateString();

        $rows = $user->dismissedRecurringSuggestions()->get()
            ->filter(fn (DismissedRecurringSuggestion $dismissal) => $dismissal->hidesOn($today))
            ->map(fn (DismissedRecurringSuggestion $dismissal) => [
                'key' => $dismissal->normalized_key,
                'label' => $dismissal->normalized_key,
                'kind' => $dismissal->kind->value,
                'snooze_until' => $dismissal->snooze_until?->toDateString(),
            ])
            ->sortBy([['kind', 'desc'], ['snooze_until', 'asc'], ['key', 'asc']])
            ->values()
            ->all();

        return $this->dismissedRows($rows);
    }

    /**
     * The normalised merchant of a description, with every alias folded into its canonical name.
     */
    private function merchantKey(?string $description): string
    {
        return $this->canonicalKey(DescriptionMatcher::normalize($description));
    }

    /**
     * @param  string  $key  an already normalised merchant
     */
    private function canonicalKey(string $key): string
    {
        foreach ((array) config('flusso.merchant_aliases', []) as $canonical => $aliases) {
            $canonicalKey = DescriptionMatcher::normalize((string) $canonical);

            foreach ((array) $aliases as $alias) {
                if (DescriptionMatcher::normalize((string) $alias) === $key) {
                    return $canonicalKey;
                }
            }
        }

        return $key;
    }

    /**
     * @param  Collection<int, Transaction>  $group  one merchant on one account, oldest first
     * @param  array<int, string>  $currencies  account id => currency
     * @param  array<int, int>  $categoryIds  the user's own categories
     * @return array{key: string, label: string, interval_type: string, interval_value: int, amount: string, previous_amount: string, currency: string, account_id: int, category_id: int|null, occurrences: int, last_date: string, next_expected: string, price_jumped: bool}|null
     */
    private function candidate(string $key, Collection $group, array $currencies, array $categoryIds, string $today): ?array
    {
        $dates = $group->map(fn (Transaction $transaction) => $transaction->date->toDateString())->values()->all();
        $amounts = $group->map(fn (Transaction $transaction) => (float) $transaction->amount)->values()->all();
        $count = count($dates);

        $interval = $this->intervalOf($dates);

        if ($interval === null || $count < self::INTERVALS[$interval]['min_charges']) {
            return null;
        }

        $latest = $amounts[$count - 1];
        $previous = $amounts[$count - 2];
        $priceJumped = $previous > 0 && $latest >= self::PRICE_JUMP_FACTOR * $previous;

        // A renewal at a much higher price (the 2 EUR first year, then 20) is the very case to surface.
        $steady = $priceJumped ? array_slice($amounts, 0, $count - 1) : $amounts;

        if (min($steady) <= 0 || max($steady) - min($steady) > self::AMOUNT_TOLERANCE * min($steady)) {
            return null;
        }

        $lastDate = $dates[$count - 1];
        $nextExpected = $this->addInterval(CarbonImmutable::parse($lastDate), $interval);

        if ($nextExpected->addDays(self::INTERVALS[$interval]['grace_days'])->toDateString() < $today) {
            return null;
        }

        /** @var Transaction $last */
        $last = $group->last();

        return [
            'key' => $key,
            'label' => (string) $last->description,
            'interval_type' => $interval,
            'interval_value' => 1,
            'amount' => (string) $last->amount,
            'previous_amount' => (string) $group[$count - 2]->amount,
            'currency' => $currencies[$last->account_id],
            'account_id' => $last->account_id,
            'category_id' => $this->mostUsedCategory($group, $categoryIds),
            'occurrences' => $count,
            'last_date' => $lastDate,
            'next_expected' => $nextExpected->toDateString(),
            'price_jumped' => $priceJumped,
        ];
    }

    /**
     * The interval every gap between the (ascending) dates fits, or null when they do not agree.
     *
     * @param  array<int, string>  $dates
     */
    private function intervalOf(array $dates): ?string
    {
        $found = null;

        for ($i = 1; $i < count($dates); $i++) {
            $gap = (int) round(CarbonImmutable::parse($dates[$i - 1])->diffInDays(CarbonImmutable::parse($dates[$i]), true));
            $fits = null;

            foreach (self::INTERVALS as $interval => $limits) {
                if ($gap >= $limits['min_gap'] && $gap <= $limits['max_gap']) {
                    $fits = $interval;
                }
            }

            if ($fits === null || ($found !== null && $found !== $fits)) {
                return null;
            }

            $found = $fits;
        }

        return $found;
    }

    private function addInterval(CarbonImmutable $date, string $interval): CarbonImmutable
    {
        return match ($interval) {
            'weeks' => $date->addWeek(),
            'months' => $date->addMonthNoOverflow(),
            default => $date->addYearNoOverflow(),
        };
    }

    /**
     * The user's category used most often by the group; the latest charge wins a tie.
     *
     * @param  Collection<int, Transaction>  $group
     * @param  array<int, int>  $categoryIds
     */
    private function mostUsedCategory(Collection $group, array $categoryIds): ?int
    {
        $used = $group->pluck('category_id')->filter(fn ($id) => in_array($id, $categoryIds, true))->values();

        if ($used->isEmpty()) {
            return null;
        }

        $counts = array_count_values($used->all());
        $best = max($counts);

        foreach ($used->reverse() as $id) {
            if ($counts[$id] === $best) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $plannedKeys  normalised names of the existing planned payments
     */
    private function isPlanned(string $key, array $plannedKeys): bool
    {
        foreach ($plannedKeys as $plannedKey) {
            if (DescriptionMatcher::similarity($key, $plannedKey) >= self::SAME_NAME_SIMILARITY) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps the declared shape: a collection's value type is invariant, so the narrower
     * types PHPStan infers would not match the declared return type.
     *
     * @param  array<int, array{key: string, label: string, interval_type: string, interval_value: int, amount: string, previous_amount: string, currency: string, account_id: int, category_id: int|null, occurrences: int, last_date: string, next_expected: string, price_jumped: bool}>  $rows
     * @return Collection<int, array{key: string, label: string, interval_type: string, interval_value: int, amount: string, previous_amount: string, currency: string, account_id: int, category_id: int|null, occurrences: int, last_date: string, next_expected: string, price_jumped: bool}>
     */
    private function suggestionRows(array $rows): Collection
    {
        return new Collection($rows);
    }

    /**
     * @param  array<int, array{key: string, label: string, kind: string, snooze_until: string|null}>  $rows
     * @return Collection<int, array{key: string, label: string, kind: string, snooze_until: string|null}>
     */
    private function dismissedRows(array $rows): Collection
    {
        return new Collection($rows);
    }
}
