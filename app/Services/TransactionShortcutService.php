<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DescriptionMatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The transactions the owner books again and again (the same account, category,
 * description and amount), offered as one-tap shortcuts that prefill the form.
 * Built only from the owner's own history, no AI. Amounts are never touched: a
 * shortcut carries the amount exactly as it was booked, in its account's currency.
 */
class TransactionShortcutService
{
    public const MAX_SHORTCUTS = 5;

    /** Patterns are looked for in this many days of history... */
    public const HISTORY_DAYS = 60;

    /** ...and must have happened at least MIN_OCCURRENCES times in this many. */
    public const RECENT_DAYS = 30;

    public const MIN_OCCURRENCES = 5;

    /** Share of a pattern's occurrences that must fall inside one window of hours to count as "usually at this time". */
    public const TIME_SHARE = 0.7;

    public const TIME_WINDOW_HOURS = 3;

    /** A pattern that is usually booked around now ranks as if it happened this much more often. */
    public const TIME_BOOST = 1.25;

    /**
     * @return Collection<int, array{account_id: int, category_id: int|null, type: string, amount: string, description: string|null, count: int, boosted: bool}>
     */
    public function shortcutsFor(User $user, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $recentSince = $now->copy()->subDays(self::RECENT_DAYS)->toDateString();
        $currentHour = $now->copy()->timezone(config('app.timezone'))->hour;

        $transactions = $user->transactions()
            ->whereIn('type', [TransactionType::Expense->value, TransactionType::Income->value])
            ->where('date', '>=', $now->copy()->subDays(self::HISTORY_DAYS)->toDateString())
            ->whereIn('account_id', $user->accounts()->select('id'))
            ->where(fn ($query) => $query
                ->whereNull('category_id')
                ->orWhereIn('category_id', $user->categories()->select('id')))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get(['id', 'account_id', 'category_id', 'type', 'amount', 'description', 'date', 'created_at']);

        return $transactions
            ->groupBy(fn (Transaction $transaction) => implode('|', [
                $transaction->account_id,
                $transaction->category_id,
                $transaction->type->value,
                DescriptionMatcher::normalize($transaction->description),
                $transaction->amount,
            ]))
            ->map(function (Collection $group) use ($recentSince, $currentHour): array {
                /** @var Transaction $latest */
                $latest = $group->first();
                $count = $group->filter(fn (Transaction $transaction) => $transaction->date->toDateString() >= $recentSince)->count();
                $hours = $group->map(fn (Transaction $transaction) => $transaction->created_at->copy()->timezone(config('app.timezone'))->hour);

                return [
                    'account_id' => $latest->account_id,
                    'category_id' => $latest->category_id,
                    'type' => $latest->type->value,
                    'amount' => $latest->amount,
                    'description' => $latest->description,
                    'count' => $count,
                    'boosted' => $this->isUsuallyBookedAround($hours, $currentHour),
                ];
            })
            ->filter(fn (array $shortcut) => $shortcut['count'] >= self::MIN_OCCURRENCES)
            ->sortByDesc(fn (array $shortcut) => $shortcut['count'] * ($shortcut['boosted'] ? self::TIME_BOOST : 1))
            ->take(self::MAX_SHORTCUTS)
            ->values();
    }

    /**
     * Whether enough of the hours fall inside one window of consecutive hours that contains the current hour.
     * Transactions only store a date, so the hour comes from when the row was created.
     *
     * @param  Collection<int, int>  $hours
     */
    private function isUsuallyBookedAround(Collection $hours, int $currentHour): bool
    {
        if ($hours->isEmpty()) {
            return false;
        }

        for ($shift = 0; $shift < self::TIME_WINDOW_HOURS; $shift++) {
            $window = [];

            for ($step = 0; $step < self::TIME_WINDOW_HOURS; $step++) {
                $window[] = ($currentHour - $shift + $step + 24) % 24;
            }

            if ($hours->filter(fn (int $hour) => in_array($hour, $window, true))->count() / $hours->count() >= self::TIME_SHARE) {
                return true;
            }
        }

        return false;
    }
}
