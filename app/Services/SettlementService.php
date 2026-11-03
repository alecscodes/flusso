<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\TransactionSettlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Matches the money someone paid back (an income) to the advances paid for them
 * (expenses). Amounts are kept in cents while checking, so sums never drift.
 */
class SettlementService
{
    /**
     * An amount in cents.
     */
    public static function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Cents back to a decimal string, e.g. 1050 -> "10.50".
     */
    public static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * How much of each transaction is already settled, in cents. Incomes count what
     * they paid, expenses what was paid towards them.
     *
     * @param  array<int, int>  $transactionIds
     * @return array<int, int> transaction id => cents
     */
    public function settledCents(array $transactionIds, TransactionType $side): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $column = $this->column($side);

        return TransactionSettlement::query()
            ->whereIn($column, $transactionIds)
            ->groupBy($column)
            ->selectRaw("{$column} as transaction_id, sum(amount) as settled")
            ->toBase()
            ->get()
            ->mapWithKeys(fn (stdClass $row) => [(int) $row->transaction_id => self::cents($row->settled)])
            ->all();
    }

    /**
     * Settle expenses (advances) with an income. One call is all-or-nothing.
     * Settling a pair again adds to what is already allocated between the two.
     *
     * @param  list<array{expense_id: int|string, amount: int|float|string}>  $allocations
     * @return Collection<int, TransactionSettlement>
     */
    public function allocate(User $user, Transaction $income, array $allocations): Collection
    {
        return DB::transaction(function () use ($user, $income, $allocations) {
            $income = Transaction::query()->lockForUpdate()->find($income->id);

            if ($income === null || $income->user_id !== $user->id || $income->type !== TransactionType::Income) {
                $this->fail('income_id', 'Pick one of your incomes to settle with.');
            }

            if ($income->counterparty_id === null) {
                $this->fail('income_id', 'This income is not from anyone yet: choose the person first.');
            }

            $available = self::cents($income->amount) - ($this->settledCents([$income->id], TransactionType::Income)[$income->id] ?? 0);
            $settlements = new Collection;

            foreach ($allocations as $index => $allocation) {
                $expense = Transaction::query()->lockForUpdate()->find((int) $allocation['expense_id']);

                if ($expense === null || $expense->user_id !== $user->id || $expense->type !== TransactionType::Expense) {
                    $this->fail("allocations.{$index}.expense_id", 'Pick one of your expenses to settle.');
                }

                if (! $expense->is_advance) {
                    $this->fail("allocations.{$index}.expense_id", 'That expense is not an advance paid for someone.');
                }

                if ($expense->counterparty_id !== $income->counterparty_id) {
                    $this->fail("allocations.{$index}.expense_id", 'The expense and the income are for different people.');
                }

                if ($expense->currency !== $income->currency) {
                    $this->fail("allocations.{$index}.expense_id", "Settling between different currencies is not supported yet: the income is in {$income->currency}, the expense in {$expense->currency}.");
                }

                $cents = self::cents($allocation['amount']);

                if ($cents <= 0) {
                    $this->fail("allocations.{$index}.amount", 'The amount to settle must be greater than zero.');
                }

                $remaining = self::cents($expense->amount) - ($this->settledCents([$expense->id], TransactionType::Expense)[$expense->id] ?? 0);

                if ($cents > $remaining) {
                    $this->fail("allocations.{$index}.amount", 'Only '.self::decimal(max($remaining, 0))." is left to settle on that expense ({$expense->currency}).");
                }

                if ($cents > $available) {
                    $this->fail("allocations.{$index}.amount", 'Only '.self::decimal(max($available, 0))." of this income is left to allocate ({$income->currency}).");
                }

                $available -= $cents;

                $existing = TransactionSettlement::query()
                    ->where('income_transaction_id', $income->id)
                    ->where('expense_transaction_id', $expense->id)
                    ->first();

                if ($existing !== null) {
                    $existing->update(['amount' => self::decimal(self::cents($existing->amount) + $cents)]);
                    $settlements->push($existing);

                    continue;
                }

                $settlements->push(TransactionSettlement::create([
                    'income_transaction_id' => $income->id,
                    'expense_transaction_id' => $expense->id,
                    'amount' => self::decimal($cents),
                ]));
            }

            return $settlements;
        });
    }

    public function remove(TransactionSettlement $settlement): void
    {
        $settlement->delete();
    }

    /**
     * The advances of a person that are not fully settled yet, oldest first, with what is left in cents.
     *
     * @return Collection<int, array{transaction: Transaction, remaining: int}>
     */
    public function openAdvances(int $userId, int $counterpartyId, ?string $currency = null): Collection
    {
        $expenses = Transaction::query()
            ->where('user_id', $userId)
            ->where('counterparty_id', $counterpartyId)
            ->where('type', TransactionType::Expense)
            ->where('is_advance', true)
            ->when($currency !== null, fn (Builder $query) => $query->where('currency', $currency))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $settled = $this->settledCents($expenses->modelKeys(), TransactionType::Expense);

        $advances = [];

        foreach ($expenses as $expense) {
            $remaining = self::cents($expense->amount) - ($settled[$expense->id] ?? 0);

            if ($remaining > 0) {
                $advances[] = ['transaction' => $expense, 'remaining' => $remaining];
            }
        }

        return $this->advanceRows($advances);
    }

    /**
     * @param  array<int, array{transaction: Transaction, remaining: int}>  $advances
     * @return Collection<int, array{transaction: Transaction, remaining: int}>
     */
    private function advanceRows(array $advances): Collection
    {
        return new Collection($advances);
    }

    /**
     * The default split of an income: the person's oldest unsettled advances first (FIFO),
     * as many as the income covers, the last one possibly in part. Nothing is saved.
     *
     * @return list<array{expense_id: int, amount: string}>
     */
    public function suggestAllocations(Transaction $income): array
    {
        if ($income->type !== TransactionType::Income || $income->counterparty_id === null) {
            return [];
        }

        $available = self::cents($income->amount) - ($this->settledCents([$income->id], TransactionType::Income)[$income->id] ?? 0);

        return self::fifo(
            $this->openAdvances($income->user_id, $income->counterparty_id, $income->currency)
                ->map(fn (array $advance) => ['id' => $advance['transaction']->id, 'remaining' => $advance['remaining']])
                ->all(),
            $available,
        );
    }

    /**
     * @param  array<int, array{id: int, remaining: int}>  $advances  oldest first, in cents
     * @return list<array{expense_id: int, amount: string}>
     */
    public static function fifo(array $advances, int $availableCents): array
    {
        $allocations = [];

        foreach ($advances as $advance) {
            if ($availableCents <= 0) {
                break;
            }

            $cents = min($advance['remaining'], $availableCents);

            if ($cents > 0) {
                $allocations[] = ['expense_id' => $advance['id'], 'amount' => self::decimal($cents)];
                $availableCents -= $cents;
            }
        }

        return $allocations;
    }

    /**
     * After an edit, what is already settled must still make sense: same person and
     * currency on both sides, an expense that is still an advance, and no more settled than the amount.
     */
    public function assertStillConsistent(Transaction $transaction): void
    {
        $asExpense = $transaction->settlementsAsExpense()->with('income')->get();
        $asIncome = $transaction->settlementsAsIncome()->with('expense')->get();

        if ($asExpense->isEmpty() && $asIncome->isEmpty()) {
            return;
        }

        if ($asExpense->isNotEmpty() && ($transaction->type !== TransactionType::Expense || ! $transaction->is_advance)) {
            $this->fail('counterparty_id', 'Incomes already settle this expense: remove those settlements before it stops being an advance.');
        }

        if ($asIncome->isNotEmpty() && $transaction->type !== TransactionType::Income) {
            $this->fail('counterparty_id', 'This income already settles expenses: remove those settlements before changing its type.');
        }

        $settledCents = self::cents($asExpense->sum('amount') + $asIncome->sum('amount'));

        if ($settledCents > self::cents($transaction->amount)) {
            $this->fail('amount', 'The amount cannot be less than the '.self::decimal($settledCents).' already settled.');
        }

        $others = $asExpense->map(fn (TransactionSettlement $settlement) => $settlement->income)
            ->concat($asIncome->map(fn (TransactionSettlement $settlement) => $settlement->expense))
            ->filter();

        foreach ($others as $other) {
            if ($other->counterparty_id !== $transaction->counterparty_id) {
                $this->fail('counterparty_id', 'Settlements link this to transactions of another person: remove them before changing the person.');
            }

            if ($other->currency !== $transaction->currency) {
                $this->fail('account_id', 'Settlements link this to transactions in another currency: remove them before changing the account.');
            }
        }
    }

    /**
     * The settled part of a set of expenses or incomes, per category and currency: what the
     * opt-in "net" totals take off. Pass a query already limited to the transactions that count.
     *
     * @param  Builder<Transaction>  $transactions
     * @return Collection<int, array{category_id: int|null, currency: string, settled: float}>
     */
    public function settledAmounts(Builder $transactions, TransactionType $side): Collection
    {
        $column = $this->column($side);

        $settled = TransactionSettlement::query()
            ->whereIn($column, $transactions->clone()->select('id'))
            ->groupBy($column)
            ->selectRaw("{$column} as transaction_id, sum(amount) as settled")
            ->toBase()
            ->get()
            ->mapWithKeys(fn (stdClass $row) => [(int) $row->transaction_id => (float) $row->settled]);

        if ($settled->isEmpty()) {
            return new Collection;
        }

        $groups = [];

        foreach ($transactions->clone()->whereKey($settled->keys()->all())->get(['id', 'category_id', 'currency']) as $transaction) {
            $key = $transaction->category_id.'|'.$transaction->currency;
            $groups[$key] ??= ['category_id' => $transaction->category_id, 'currency' => $transaction->currency, 'settled' => 0.0];
            $groups[$key]['settled'] += (float) $settled[$transaction->id];
        }

        return $this->settledRows(array_values($groups));
    }

    /**
     * @param  array<int, array{category_id: int|null, currency: string, settled: float}>  $rows
     * @return Collection<int, array{category_id: int|null, currency: string, settled: float}>
     */
    private function settledRows(array $rows): Collection
    {
        return new Collection($rows);
    }

    private function column(TransactionType $side): string
    {
        return $side === TransactionType::Income ? 'income_transaction_id' : 'expense_transaction_id';
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
