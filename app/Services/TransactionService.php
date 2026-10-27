<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        private CurrencyService $currencyService,
        private FileUploadService $fileUploadService
    ) {}

    /**
     * Create an expense, income or transfer.
     *
     * @param  array{account_id: int, type: string|TransactionType, amount: numeric, date?: string|null, description?: string|null, category_id?: int|null, to_account_id?: int|null, to_amount?: numeric|null, recurring_payment_id?: int|null, files?: array<int, mixed>}  $data
     */
    public function createTransaction(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data) {
            $transaction = $user->transactions()->create([
                ...$this->attributes($user, $data),
                'recurring_payment_id' => $data['recurring_payment_id'] ?? null,
            ]);

            if (isset($data['files'])) {
                $this->fileUploadService->handleTransactionFiles($transaction, $data['files']);
            }

            return $transaction->load(['account', 'category', 'toAccount', 'files']);
        });
    }

    /**
     * Replace a transaction's data. Nullable fields that are absent are cleared.
     *
     * @param  array{account_id: int, type: string|TransactionType, amount: numeric, date?: string|null, description?: string|null, category_id?: int|null, to_account_id?: int|null, to_amount?: numeric|null, files?: array<int, mixed>}  $data
     */
    public function updateTransaction(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $transaction->update($this->attributes($transaction->user, $data));

            if (isset($data['files'])) {
                $this->fileUploadService->handleTransactionFiles($transaction, $data['files']);
            }

            return $transaction->load(['account', 'category', 'toAccount', 'files']);
        });
    }

    /**
     * Delete a transaction; the observer recalculates the affected balances.
     */
    public function deleteTransaction(Transaction $transaction): void
    {
        DB::transaction(fn () => $transaction->delete());
    }

    /**
     * The writable columns for a validated payload. Currency, category and
     * same-currency destination amounts are normalised by the TransactionObserver;
     * what is left here is looking up the accounts and, between currencies, the amount received.
     *
     * @param  array{account_id: int, type: string|TransactionType, amount: numeric, date?: string|null, description?: string|null, category_id?: int|null, to_account_id?: int|null, to_amount?: numeric|null}  $data
     * @return array<string, mixed>
     */
    private function attributes(User $user, array $data): array
    {
        $type = TransactionType::from($data['type'] instanceof TransactionType ? $data['type']->value : $data['type']);
        $account = $user->accounts()->withTrashed()->findOrFail($data['account_id']);

        $attributes = [
            'account_id' => $account->id,
            'category_id' => $data['category_id'] ?? null,
            'type' => $type,
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
            'date' => $data['date'] ?? now()->toDateString(),
            'to_account_id' => null,
            'to_amount' => null,
        ];

        if ($type === TransactionType::Transfer) {
            $toAccount = $user->accounts()->withTrashed()->findOrFail($data['to_account_id']);

            $attributes['to_account_id'] = $toAccount->id;
            $attributes['to_amount'] = $data['to_amount'] ?? ($account->currency === $toAccount->currency
                ? null
                : $this->receivedAmount($account, $toAccount, (float) $data['amount']));
        }

        return $attributes;
    }

    /**
     * What arrives in another currency when the owner did not say: today's rate
     * (never an old one, it is saved).
     */
    private function receivedAmount(Account $from, Account $to, float $amount): float
    {
        $rate = $this->currencyService->getRate($from->currency, $to->currency, allowStale: false);

        if ($rate === null) {
            throw ValidationException::withMessages([
                'to_amount' => 'Unable to fetch today\'s exchange rate. Enter the amount received.',
            ]);
        }

        return round($amount * $rate, 2);
    }

    /**
     * Income, expenses and the number of transactions in a period, in the primary currency.
     *
     * @return array{income: float, expenses: float, net: float, transaction_count: int}
     */
    public function getTransactionSummaryForPeriod(User $user, CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $rows = $user->transactions()
            ->inPeriod($startDate, $endDate)
            ->selectRaw('type, currency, sum(amount) as total, count(*) as aggregate')
            ->groupBy('type', 'currency')
            ->get();

        $inPrimaryCurrency = fn (TransactionType $type): float => $this->currencyService->sumInPrimaryCurrency(
            $rows->where('type', $type),
            'total',
            'currency',
            $user,
        );

        $income = $inPrimaryCurrency(TransactionType::Income);
        $expenses = $inPrimaryCurrency(TransactionType::Expense);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'net' => $income - $expenses,
            'transaction_count' => (int) $rows->sum('aggregate'),
        ];
    }

    /**
     * Expense totals per category (in the primary currency), biggest first. A null
     * date bound leaves that side open; `$filters` are the list filters (search,
     * account, category), so the totals describe what the list shows.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{category: Category|null, total: float, count: int}>
     */
    public function getCategorySpendingForPeriod(User $user, ?CarbonInterface $startDate, ?CarbonInterface $endDate, int $limit = 10, array $filters = []): Collection
    {
        $rows = $user->transactions()
            ->filter($filters)
            ->inPeriod($startDate, $endDate)
            ->where('type', TransactionType::Expense)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, currency, sum(amount) as total, count(*) as aggregate')
            ->groupBy('category_id', 'currency')
            ->get();

        $categories = $user->categories()->withTrashed()->whereKey($rows->pluck('category_id')->unique())->get()->keyBy('id');
        $primaryCurrency = $this->currencyService->getPrimaryCurrency($user);

        return $rows
            ->groupBy('category_id')
            ->map(fn (Collection $group, int $categoryId) => [
                'category' => $categories->get($categoryId),
                'total' => (float) $this->currencyService->sumInPrimaryCurrency($group, 'total', 'currency', $user),
                'count' => (int) $group->sum('aggregate'),
            ])
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }
}
