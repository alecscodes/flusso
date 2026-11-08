<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Counterparty;
use App\Models\Transaction;
use App\Models\TransactionSettlement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * People the owner pays for or gets paid back by, and the running balance with each of them.
 */
class CounterpartyService
{
    public function __construct(private SettlementService $settlements) {}

    /**
     * The person with this name (ignoring case and surrounding spaces), if the user has one.
     */
    public function findByName(User $user, string $name): ?Counterparty
    {
        $key = mb_strtolower(trim($name));

        return $user->counterparties()->get()
            ->first(fn (Counterparty $counterparty) => mb_strtolower($counterparty->name) === $key);
    }

    public function findOrCreate(User $user, string $name): Counterparty
    {
        return $this->findByName($user, $name) ?? $user->counterparties()->create(['name' => trim($name)]);
    }

    /**
     * @param  array{name: string}  $data
     */
    public function create(User $user, array $data): Counterparty
    {
        return $user->counterparties()->create(['name' => $data['name']]);
    }

    /**
     * @param  array{name: string}  $data
     */
    public function update(Counterparty $counterparty, array $data): Counterparty
    {
        $counterparty->update(['name' => $data['name']]);

        return $counterparty;
    }

    /**
     * Deleting a person unlinks their transactions (they stay, as plain ones) and drops the settlements between them.
     */
    public function delete(Counterparty $counterparty): void
    {
        DB::transaction(function () use ($counterparty) {
            $ids = Transaction::withTrashed()->where('counterparty_id', $counterparty->id)->pluck('id');

            TransactionSettlement::query()
                ->whereIn('income_transaction_id', $ids)
                ->orWhereIn('expense_transaction_id', $ids)
                ->delete();

            Transaction::withTrashed()->where('counterparty_id', $counterparty->id)->update(['counterparty_id' => null, 'is_advance' => false]);

            $counterparty->delete();
        });
    }

    /**
     * Give a transaction without a person to this one. Only incomes and expenses of the same user qualify.
     */
    public function assign(User $user, Counterparty $counterparty, int $transactionId): Transaction
    {
        $transaction = $user->transactions()->find($transactionId);

        if ($transaction === null || $transaction->type === TransactionType::Transfer || $transaction->counterparty_id !== null || $counterparty->user_id !== $user->id) {
            throw ValidationException::withMessages(['transaction_id' => 'Pick one of your incomes or expenses that has no person yet.']);
        }

        $transaction->update(['counterparty_id' => $counterparty->id]);

        return $transaction;
    }

    /**
     * The person an income's description names, if any (never assigned automatically). When
     * several names fit, the longest wins ("Irina Popescu" over "Irina").
     */
    public function suggestForDescription(User $user, ?string $description): ?Counterparty
    {
        return $this->matchName($user->counterparties()->get(), $description);
    }

    /**
     * @param  Collection<int, Counterparty>  $counterparties
     */
    private function matchName(Collection $counterparties, ?string $description): ?Counterparty
    {
        $text = mb_strtolower(trim((string) $description));

        if ($text === '') {
            return null;
        }

        return $counterparties
            ->sortByDesc(fn (Counterparty $counterparty) => mb_strlen($counterparty->name))
            ->first(fn (Counterparty $counterparty) => trim($counterparty->name) !== ''
                && preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower(trim($counterparty->name)), '/').'(?![\p{L}\p{N}])/u', $text) === 1);
    }

    /**
     * Recent incomes without a person whose description names one of the user's people.
     *
     * @return list<array{transaction_id: int, date: string, amount: float, currency: string, description: string|null, counterparty_id: int, counterparty_name: string}>
     */
    public function suggestionsForUnassignedIncomes(User $user, int $limit = 50): array
    {
        $counterparties = $user->counterparties()->get();

        if ($counterparties->isEmpty()) {
            return [];
        }

        $suggestions = [];

        $user->transactions()
            ->where('type', TransactionType::Income)
            ->whereNull('counterparty_id')
            ->whereNotNull('description')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->each(function (Transaction $income) use ($counterparties, $limit, &$suggestions) {
                $counterparty = count($suggestions) < $limit ? $this->matchName($counterparties, $income->description) : null;

                if ($counterparty !== null) {
                    $suggestions[] = [
                        'transaction_id' => $income->id,
                        'date' => $income->date->toDateString(),
                        'amount' => (float) $income->amount,
                        'currency' => $income->currency,
                        'description' => $income->description,
                        'counterparty_id' => $counterparty->id,
                        'counterparty_name' => $counterparty->name,
                    ];
                }
            });

        return $suggestions;
    }

    /**
     * Per person, per currency: what was advanced, how much of it is settled, what is left,
     * what they paid back and how much of that is not matched to anything yet. `balance` is
     * advanced minus received: positive means they owe the owner, negative that they
     * overpaid (the owner owes them).
     *
     * `over_settled_count` is how many transactions of the person are settled for more than their amount.
     *
     * @return Collection<int, array{id: int, name: string, over_settled_count: int, balances: list<array{currency: string, advanced: float, settled: float, outstanding: float, received: float, unallocated: float, balance: float}>}>
     */
    public function balances(User $user, ?int $counterpartyId = null): Collection
    {
        $totals = [];
        $blank = ['advanced' => 0, 'received' => 0, 'settled' => 0];

        $transactions = fn (TransactionType $type) => Transaction::query()
            ->where('user_id', $user->id)
            ->whereNotNull('counterparty_id')
            ->when($counterpartyId !== null, fn ($query) => $query->where('counterparty_id', $counterpartyId))
            ->where('type', $type)
            ->groupBy('counterparty_id', 'currency')
            ->selectRaw('counterparty_id, currency, sum(amount) as total');

        foreach ($transactions(TransactionType::Expense)->where('is_advance', true)->get() as $row) {
            $totals[$row->counterparty_id][$row->currency] ??= $blank;
            $totals[$row->counterparty_id][$row->currency]['advanced'] += SettlementService::cents($row->getAttribute('total'));
        }

        foreach ($transactions(TransactionType::Income)->get() as $row) {
            $totals[$row->counterparty_id][$row->currency] ??= $blank;
            $totals[$row->counterparty_id][$row->currency]['received'] += SettlementService::cents($row->getAttribute('total'));
        }

        $settled = TransactionSettlement::query()
            ->live()
            ->join('transactions', 'transactions.id', '=', 'transaction_settlements.expense_transaction_id')
            ->where('transactions.user_id', $user->id)
            ->whereNull('transactions.deleted_at')
            ->whereNotNull('transactions.counterparty_id')
            ->when($counterpartyId !== null, fn ($query) => $query->where('transactions.counterparty_id', $counterpartyId))
            ->groupBy('transactions.counterparty_id', 'transactions.currency')
            ->selectRaw('transactions.counterparty_id as counterparty_id, transactions.currency as currency, sum(transaction_settlements.amount) as total')
            ->toBase()
            ->get();

        foreach ($settled as $row) {
            $totals[$row->counterparty_id][$row->currency] ??= $blank;
            $totals[$row->counterparty_id][$row->currency]['settled'] += SettlementService::cents($row->total);
        }

        $overSettledCounts = array_count_values(array_column($this->overSettled($user, $counterpartyId), 'counterparty_id'));
        $rows = [];

        foreach ($user->counterparties()->when($counterpartyId !== null, fn ($query) => $query->whereKey($counterpartyId))->orderBy('name')->get() as $counterparty) {
            $balances = [];
            $byCurrency = $totals[$counterparty->id] ?? [];
            ksort($byCurrency);

            foreach ($byCurrency as $currency => $sum) {
                $balances[] = [
                    'currency' => (string) $currency,
                    'advanced' => (float) ($sum['advanced'] / 100),
                    'settled' => (float) ($sum['settled'] / 100),
                    'outstanding' => (float) (($sum['advanced'] - $sum['settled']) / 100),
                    'received' => (float) ($sum['received'] / 100),
                    'unallocated' => (float) (($sum['received'] - $sum['settled']) / 100),
                    'balance' => (float) (($sum['advanced'] - $sum['received']) / 100),
                ];
            }

            $rows[] = [
                'id' => $counterparty->id,
                'name' => $counterparty->name,
                'over_settled_count' => $overSettledCounts[$counterparty->id] ?? 0,
                'balances' => $balances,
            ];
        }

        return $this->balanceRows($rows);
    }

    /**
     * @param  array<int, array{id: int, name: string, over_settled_count: int, balances: list<array{currency: string, advanced: float, settled: float, outstanding: float, received: float, unallocated: float, balance: float}>}>  $rows
     * @return Collection<int, array{id: int, name: string, over_settled_count: int, balances: list<array{currency: string, advanced: float, settled: float, outstanding: float, received: float, unallocated: float, balance: float}>}>
     */
    private function balanceRows(array $rows): Collection
    {
        return new Collection($rows);
    }

    /**
     * Transactions settled for more than their amount, which restoring a deleted transaction can
     * cause (nothing is trimmed then). Compared in cents in SQL, live settlements only. `settlement_ids`
     * are the settlements to remove one of.
     *
     * @return list<array{transaction_id: int, counterparty_id: int, side: string, currency: string, date: string, description: string|null, amount: float, settled: float, over: float, settlement_ids: list<int>}>
     */
    public function overSettled(User $user, ?int $counterpartyId = null): array
    {
        $rows = [];

        foreach ([TransactionType::Expense, TransactionType::Income] as $side) {
            $column = $side === TransactionType::Income ? 'income_transaction_id' : 'expense_transaction_id';

            $found = TransactionSettlement::query()
                ->live()
                ->join('transactions', 'transactions.id', '=', "transaction_settlements.{$column}")
                ->where('transactions.user_id', $user->id)
                ->whereNull('transactions.deleted_at')
                ->whereNotNull('transactions.counterparty_id')
                ->when($counterpartyId !== null, fn ($query) => $query->where('transactions.counterparty_id', $counterpartyId))
                ->groupBy('transactions.id', 'transactions.counterparty_id', 'transactions.currency', 'transactions.date', 'transactions.description', 'transactions.amount')
                ->havingRaw('round(sum(transaction_settlements.amount) * 100) > round(transactions.amount * 100)')
                ->selectRaw('transactions.id as id, transactions.counterparty_id as counterparty_id, transactions.currency as currency, transactions.date as date, transactions.description as description, transactions.amount as amount, sum(transaction_settlements.amount) as settled, group_concat(transaction_settlements.id) as settlement_ids')
                ->orderBy('transactions.date')
                ->orderBy('transactions.id')
                ->toBase()
                ->get();

            foreach ($found as $row) {
                $amount = SettlementService::cents($row->amount);
                $settled = SettlementService::cents($row->settled);
                $ids = array_map('intval', explode(',', (string) $row->settlement_ids));
                sort($ids);

                $rows[] = [
                    'transaction_id' => (int) $row->id,
                    'counterparty_id' => (int) $row->counterparty_id,
                    'side' => $side->value,
                    'currency' => (string) $row->currency,
                    'date' => substr((string) $row->date, 0, 10),
                    'description' => $row->description === null ? null : (string) $row->description,
                    'amount' => (float) ($amount / 100),
                    'settled' => (float) ($settled / 100),
                    'over' => (float) (($settled - $amount) / 100),
                    'settlement_ids' => $ids,
                ];
            }
        }

        return $rows;
    }

    /**
     * Everything the person's page shows: the balance, their advances and repayments
     * (with what is settled) and the settlements between them. Each repayment carries the FIFO suggestion.
     *
     * @return array<string, mixed>
     */
    public function details(User $user, Counterparty $counterparty): array
    {
        $balance = $this->balances($user, $counterparty->id)->first();

        $expenses = $user->transactions()
            ->where('counterparty_id', $counterparty->id)
            ->where('type', TransactionType::Expense)
            ->where('is_advance', true)
            ->orderBy('date')->orderBy('id')
            ->get();

        $incomes = $user->transactions()
            ->where('counterparty_id', $counterparty->id)
            ->where('type', TransactionType::Income)
            ->orderBy('date')->orderBy('id')
            ->get();

        $settledOfExpense = $this->settlements->settledCents($expenses->modelKeys(), TransactionType::Expense);
        $settledOfIncome = $this->settlements->settledCents($incomes->modelKeys(), TransactionType::Income);

        $open = $expenses
            ->map(fn (Transaction $expense) => [
                'id' => $expense->id,
                'currency' => $expense->currency,
                'remaining' => SettlementService::cents($expense->amount) - ($settledOfExpense[$expense->id] ?? 0),
            ])
            ->filter(fn (array $advance) => $advance['remaining'] > 0);

        return [
            'balances' => $balance['balances'] ?? [],
            'advances' => $expenses->map(fn (Transaction $expense) => [
                'id' => $expense->id,
                'date' => $expense->date->toDateString(),
                'amount' => (float) $expense->amount,
                'currency' => $expense->currency,
                'description' => $expense->description,
                'settled' => (float) (($settledOfExpense[$expense->id] ?? 0) / 100),
                'remaining' => (float) ((SettlementService::cents($expense->amount) - ($settledOfExpense[$expense->id] ?? 0)) / 100),
            ])->values()->all(),
            'repayments' => $incomes->map(function (Transaction $income) use ($settledOfIncome, $open) {
                $unallocated = SettlementService::cents($income->amount) - ($settledOfIncome[$income->id] ?? 0);

                return [
                    'id' => $income->id,
                    'date' => $income->date->toDateString(),
                    'amount' => (float) $income->amount,
                    'currency' => $income->currency,
                    'description' => $income->description,
                    'allocated' => (float) (($settledOfIncome[$income->id] ?? 0) / 100),
                    'unallocated' => (float) ($unallocated / 100),
                    'suggestion' => SettlementService::fifo(
                        $open->where('currency', $income->currency)->map(fn (array $advance) => ['id' => $advance['id'], 'remaining' => $advance['remaining']])->values()->all(),
                        $unallocated,
                    ),
                ];
            })->values()->all(),
            'over_settled' => $this->overSettled($user, $counterparty->id),
            'settlements' => TransactionSettlement::query()
                ->live()
                ->whereIn('income_transaction_id', $incomes->modelKeys())
                ->orderBy('id')
                ->get()
                ->map(fn (TransactionSettlement $settlement) => [
                    'id' => $settlement->id,
                    'income_id' => $settlement->income_transaction_id,
                    'expense_id' => $settlement->expense_transaction_id,
                    'amount' => (float) $settlement->amount,
                ])->all(),
        ];
    }
}
