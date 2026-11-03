<?php

namespace App\Services\Statements;

use App\Enums\StatementLineStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Models\User;
use App\Services\CategorySuggestionService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;

/**
 * Turns chosen statement lines into transactions. A line is only ever imported
 * from `unmatched`, and just before anything is created the matcher runs again,
 * so a line that is already in the app is linked, not duplicated.
 *
 * The whole batch is one DB transaction: a line that cannot be imported is
 * refused with a reason (and creates nothing) while the others go on; an
 * unexpected error rolls the entire batch back. The lines are locked, so a double
 * submit cannot import one line twice. Transactions are created through
 * TransactionService (the ledger keeps the balances), with the amount exactly as
 * printed on the statement, never converted.
 *
 * @phpstan-type ImportResult array{imported: int, already_in_app: int, refused: list<array{line_id: int, reason: string}>}
 */
class StatementImporter
{
    public function __construct(
        private readonly StatementMatcher $matcher,
        private readonly TransactionService $transactionService,
        private readonly CategorySuggestionService $categorySuggestions,
    ) {}

    /**
     * @param  array<int, array{description?: string|null, category_id?: int|null}>  $choices  per line id: the description and category the owner chose
     * @param  bool  $allUnmatched  also import every unmatched line of the statement that has no choice (the category then comes from the owner's history)
     * @return ImportResult
     */
    public function import(User $user, Statement $statement, Account $account, array $choices, bool $allUnmatched = false): array
    {
        return DB::transaction(function () use ($user, $statement, $account, $choices, $allUnmatched) {
            $ids = array_keys($choices);

            if ($allUnmatched) {
                $ids = [...$ids, ...$statement->lines()->where('match_status', StatementLineStatus::Unmatched->value)->pluck('id')->all()];
            }

            $lines = StatementLine::query()
                ->where('statement_id', $statement->id)
                ->where('user_id', $user->id)
                ->whereIn('id', array_values(array_unique($ids)))
                ->orderBy('posted_on')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $matches = $this->matcher->matchesFor($statement, $account->id);

            $categoryTypes = $user->categories()->get(['id', 'type'])
                ->mapWithKeys(fn (Category $category) => [$category->id => $category->type->value])
                ->all();

            $suggested = $allUnmatched
                ? $this->suggestCategories($user, $lines->filter(fn (StatementLine $line) => ! isset($choices[$line->id])))
                : [];

            $result = ['imported' => 0, 'already_in_app' => 0, 'refused' => []];

            foreach ($lines as $line) {
                if ($line->match_status !== StatementLineStatus::Unmatched) {
                    $result['refused'][] = ['line_id' => $line->id, 'reason' => 'Only lines that are not in Flusso can be imported.'];

                    continue;
                }

                if (isset($matches[$line->id])) {
                    $line->forceFill(['match_status' => StatementLineStatus::Matched, 'matched_transaction_id' => $matches[$line->id]])->save();
                    $result['already_in_app']++;

                    continue;
                }

                $choice = $choices[$line->id] ?? [];
                $categoryId = array_key_exists('category_id', $choice) ? $choice['category_id'] : ($suggested[$line->id] ?? null);
                $reason = $this->refusal($line, $account, $categoryId, $categoryTypes);

                if ($reason !== null) {
                    $result['refused'][] = ['line_id' => $line->id, 'reason' => $reason];

                    continue;
                }

                $transaction = $this->transactionService->createTransaction($user, [
                    'account_id' => $account->id,
                    'type' => $this->typeFor($line) ?? TransactionType::Expense,
                    'amount' => ltrim((string) $line->amount, '-'),
                    'date' => $line->posted_on->toDateString(),
                    'description' => filled($choice['description'] ?? null) ? $choice['description'] : $line->description,
                    'category_id' => $categoryId,
                ]);

                $line->forceFill(['match_status' => StatementLineStatus::Imported, 'matched_transaction_id' => $transaction->id])->save();
                $result['imported']++;
            }

            return $result;
        });
    }

    /**
     * The category the owner's history points to for each line (by line id), for the lines that have one.
     *
     * @param  iterable<StatementLine>  $lines
     * @return array<int, int>
     */
    public function suggestCategories(User $user, iterable $lines): array
    {
        $history = [];
        $suggested = [];

        foreach ($lines as $line) {
            $type = $this->typeFor($line);

            if ($type === null) {
                continue;
            }

            $history[$type->value] ??= $this->categorySuggestions->history($user, $type);
            $match = $this->categorySuggestions->fromHistory($history[$type->value], $line->description);

            if ($match !== null) {
                $suggested[$line->id] = $match['category_id'];
            }
        }

        return $suggested;
    }

    /**
     * Why the line cannot be imported into the account, or null when it can.
     *
     * @param  array<int, string>  $categoryTypes  the owner's categories: type by id
     */
    private function refusal(StatementLine $line, Account $account, ?int $categoryId, array $categoryTypes): ?string
    {
        $type = $this->typeFor($line);

        if ($type === null) {
            return 'A line of 0.00 is not a transaction.';
        }

        if ($line->currency !== null && strtoupper($line->currency) !== strtoupper($account->currency)) {
            return "The line is in {$line->currency} but the account is in {$account->currency}; nothing is converted.";
        }

        if ($categoryId !== null && ! isset($categoryTypes[$categoryId])) {
            return 'That category is not available.';
        }

        if ($categoryId !== null && $categoryTypes[$categoryId] !== $type->value) {
            return "The category does not fit an {$type->value}.";
        }

        return null;
    }

    /**
     * Money out is an expense and money in an income; zero is neither.
     */
    private function typeFor(StatementLine $line): ?TransactionType
    {
        $amount = (string) $line->amount;

        if ((float) $amount === 0.0) {
            return null;
        }

        return str_starts_with($amount, '-') ? TransactionType::Expense : TransactionType::Income;
    }
}
