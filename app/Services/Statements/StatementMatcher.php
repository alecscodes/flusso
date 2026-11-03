<?php

namespace App\Services\Statements;

use App\Enums\StatementLineStatus;
use App\Enums\TransactionType;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Approximate matching of statement lines against the transactions of the
 * statement's account (it never writes to transactions). A line matches a
 * transaction with the same absolute amount, to the cent, within three days.
 * When several fit, the sign, a similar description and the exact date break the
 * tie; if that still leaves more than one the line stays unmatched and the owner
 * sees the candidates. A transaction matches at most one line.
 *
 * A transfer is one row that touches both of its accounts: it counts as money out
 * of its source and as `to_amount` into its destination.
 *
 * @phpstan-type Entry array{transaction: Transaction, signed: float, days: int, score: int}
 */
class StatementMatcher
{
    public const DAY_WINDOW = 3;

    /**
     * Match the statement's open lines (unmatched or matched; imported and
     * ignored ones are left alone) and store the result.
     *
     * @return array{matched: int, unmatched: int}
     */
    public function match(Statement $statement): array
    {
        $analysis = $this->analyze($statement);
        $matched = 0;
        $unmatched = 0;

        DB::transaction(function () use ($analysis, &$matched, &$unmatched) {
            foreach ($analysis['lines'] as $line) {
                $transactionId = $analysis['matches'][$line->id] ?? null;
                $status = $transactionId !== null ? StatementLineStatus::Matched : StatementLineStatus::Unmatched;

                if ($transactionId !== null) {
                    $matched++;
                } else {
                    $unmatched++;
                }

                if ($line->match_status !== $status || $line->matched_transaction_id !== $transactionId) {
                    $line->forceFill(['match_status' => $status, 'matched_transaction_id' => $transactionId])->save();
                }
            }
        });

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * For each unmatched line that has look-alikes, the transactions that nearly fit
     * (and are not already matched by another line of the statement).
     *
     * @return array<int, list<Transaction>> keyed by statement line id
     */
    public function candidates(Statement $statement): array
    {
        $analysis = $this->analyze($statement);

        $alreadyMatched = $statement->lines()
            ->whereNotNull('matched_transaction_id')
            ->pluck('matched_transaction_id')
            ->flip()
            ->all();

        $candidates = [];

        foreach ($analysis['lines'] as $line) {
            if ($line->match_status !== StatementLineStatus::Unmatched) {
                continue;
            }

            $options = array_values(array_filter(
                $analysis['options'][$line->id] ?? [],
                fn (array $entry) => ! isset($alreadyMatched[$entry['transaction']->id]),
            ));

            if ($options !== []) {
                $candidates[$line->id] = array_map(fn (array $entry) => $entry['transaction'], $options);
            }
        }

        return $candidates;
    }

    /**
     * @return array{lines: Collection<int, StatementLine>, matches: array<int, int>, options: array<int, list<Entry>>}
     */
    private function analyze(Statement $statement): array
    {
        $lines = $statement->lines()
            ->whereIn('match_status', [StatementLineStatus::Unmatched->value, StatementLineStatus::Matched->value])
            ->orderBy('posted_on')
            ->orderBy('id')
            ->get();

        if ($statement->account_id === null || $lines->isEmpty()) {
            return ['lines' => $lines, 'matches' => [], 'options' => []];
        }

        $accountId = (int) $statement->account_id;
        $dayNumbers = $lines->map(fn (StatementLine $line) => $this->dayNumber($line->posted_on->toDateString()));
        $from = gmdate('Y-m-d', (int) (($dayNumbers->min() - self::DAY_WINDOW) * 86400));
        $to = gmdate('Y-m-d', (int) (($dayNumbers->max() + self::DAY_WINDOW) * 86400));

        // Transactions already matched to (or imported from) lines outside this open set are taken,
        // but only by statements of this same account: a transfer is legitimately on two accounts' statements.
        $taken = StatementLine::query()
            ->where('user_id', $statement->user_id)
            ->whereIn('statement_id', Statement::query()->where('account_id', $accountId)->select('id'))
            ->whereNotNull('matched_transaction_id')
            ->whereNotIn('id', $lines->modelKeys())
            ->pluck('matched_transaction_id')
            ->flip()
            ->all();

        $pool = [];

        $transactions = Transaction::query()
            ->where('user_id', $statement->user_id)
            ->forAccount($accountId)
            ->inPeriod($from, $to)
            ->get();

        foreach ($transactions as $transaction) {
            if (isset($taken[$transaction->id])) {
                continue;
            }

            $signed = $this->signedAmount($transaction, $accountId);
            $pool[$this->cents($signed)][] = ['transaction' => $transaction, 'signed' => $signed];
        }

        $options = [];
        $claims = [];

        foreach ($lines as $line) {
            $lineDay = $this->dayNumber($line->posted_on->toDateString());
            $entries = [];

            foreach ($pool[$this->cents((float) $line->amount)] ?? [] as $candidate) {
                $days = abs($this->dayNumber($candidate['transaction']->date->toDateString()) - $lineDay);

                if ($days > self::DAY_WINDOW) {
                    continue;
                }

                $entries[] = [
                    'transaction' => $candidate['transaction'],
                    'signed' => $candidate['signed'],
                    'days' => $days,
                    'score' => $this->similarity($line->description, $candidate['transaction']->description),
                ];
            }

            $options[$line->id] = $entries;
            $chosen = $this->choose($entries, (float) $line->amount > 0);

            if ($chosen !== null) {
                $claims[$chosen['transaction']->id][] = ['line' => $line->id, 'days' => $chosen['days'], 'score' => $chosen['score']];
            }
        }

        // One transaction, one line: the closest and most similar claimant wins.
        $matches = [];

        foreach ($claims as $transactionId => $claimants) {
            usort($claimants, fn (array $a, array $b) => [$a['days'], -$a['score'], $a['line']] <=> [$b['days'], -$b['score'], $b['line']]);
            $matches[$claimants[0]['line']] = $transactionId;
        }

        return ['lines' => $lines, 'matches' => $matches, 'options' => $options];
    }

    /**
     * The one entry a line matches, or null when none or several fit.
     *
     * @param  list<Entry>  $entries
     * @return Entry|null
     */
    private function choose(array $entries, bool $incoming): ?array
    {
        if (count($entries) <= 1) {
            return $entries[0] ?? null;
        }

        $set = $this->narrow($entries, fn (array $entry) => ($entry['signed'] > 0) === $incoming);

        if (count($set) > 1) {
            $set = $this->narrow($set, fn (array $entry) => $entry['score'] > 0);
        }

        if (count($set) > 1) {
            $set = $this->narrow($set, fn (array $entry) => $entry['days'] === 0);
        }

        return count($set) === 1 ? $set[0] : null;
    }

    /**
     * Only the entries passing the test, unless none does (a preference, never a rule).
     *
     * @param  list<Entry>  $entries
     * @param  callable(Entry): bool  $test
     * @return list<Entry>
     */
    private function narrow(array $entries, callable $test): array
    {
        $kept = array_values(array_filter($entries, $test));

        return $kept === [] ? $entries : $kept;
    }

    /**
     * The effect of a transaction on the account: negative out, positive in.
     */
    private function signedAmount(Transaction $transaction, int $accountId): float
    {
        if ($transaction->type === TransactionType::Transfer && (int) $transaction->to_account_id === $accountId && (int) $transaction->account_id !== $accountId) {
            return (float) ($transaction->to_amount ?? $transaction->amount);
        }

        return $transaction->type === TransactionType::Income ? (float) $transaction->amount : -(float) $transaction->amount;
    }

    private function cents(float $amount): int
    {
        return (int) round(abs($amount) * 100);
    }

    private function dayNumber(string $date): int
    {
        return intdiv((int) strtotime($date.' 00:00:00 UTC'), 86400);
    }

    /**
     * How many meaningful words the two descriptions share (0 when either is empty).
     */
    private function similarity(?string $a, ?string $b): int
    {
        $wordsA = $this->words($a);
        $wordsB = $this->words($b);

        return count(array_intersect($wordsA, $wordsB));
    }

    /**
     * @return list<string>
     */
    private function words(?string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii((string) $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word) => strlen($word) >= 3)));
    }
}
