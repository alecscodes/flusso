<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
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
     * Get transactions for a user with optional limit.
     */
    public function getTransactionsForUser(User $user, ?int $limit = null): Collection
    {
        $query = $user->transactions()
            ->with(['account', 'category', 'toAccount'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        return $limit ? $query->limit($limit)->get() : $query->get();
    }

    /**
     * Get transactions for a period (inclusive); a null bound leaves that side open.
     */
    public function getTransactionsForPeriod(User $user, ?CarbonInterface $startDate, ?CarbonInterface $endDate): Collection
    {
        return $user->transactions()
            ->with(['account', 'category', 'toAccount'])
            ->inPeriod($startDate, $endDate)
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

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

            if (isset($data['files']) && is_array($data['files'])) {
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

            if (isset($data['files']) && is_array($data['files'])) {
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
        $transaction->delete();
    }

    /**
     * The writable columns for a validated payload. Currency, category and
     * destination consistency are enforced by the TransactionObserver.
     *
     * @return array<string, mixed>
     */
    private function attributes(User $user, array $data): array
    {
        $type = TransactionType::from($data['type'] instanceof TransactionType ? $data['type']->value : $data['type']);
        $account = $user->accounts()->findOrFail($data['account_id']);

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
            $toAccount = $user->accounts()->findOrFail($data['to_account_id']);

            $attributes['to_account_id'] = $toAccount->id;
            $attributes['to_amount'] = $data['to_amount'] ?? $this->receivedAmount($account, $toAccount, (float) $data['amount']);
        }

        return $attributes;
    }

    /**
     * What arrives in the destination account when the owner did not say: the
     * same amount between same-currency accounts, otherwise today's rate.
     */
    private function receivedAmount(Account $from, Account $to, float $amount): float
    {
        $rate = $this->currencyService->getRate($from->currency, $to->currency);

        if ($rate === null) {
            throw ValidationException::withMessages([
                'to_amount' => 'Unable to fetch the exchange rate. Enter the amount received.',
            ]);
        }

        return round($amount * $rate, 2);
    }

    /**
     * Get transaction summary for a period with currency conversion.
     */
    public function getTransactionSummaryForPeriod(User $user, CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $transactions = $this->getTransactionsForPeriod($user, $startDate, $endDate);

        $incomeTransactions = $transactions->where('type', TransactionType::Income);
        $expenseTransactions = $transactions->where('type', TransactionType::Expense);

        $income = $this->currencyService->sumInPrimaryCurrency($incomeTransactions, 'amount', 'currency', $user);
        $expenses = $this->currencyService->sumInPrimaryCurrency($expenseTransactions, 'amount', 'currency', $user);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'net' => $income - $expenses,
            'transaction_count' => $transactions->count(),
        ];
    }

    /**
     * Get category spending (in the primary currency) for a period; a null bound leaves that side open.
     */
    public function getCategorySpendingForPeriod(User $user, ?CarbonInterface $startDate, ?CarbonInterface $endDate, int $limit = 10): Collection
    {
        $expenseTransactions = $this->getTransactionsForPeriod($user, $startDate, $endDate)
            ->where('type', TransactionType::Expense)
            ->whereNotNull('category_id');

        $primaryCurrency = $this->currencyService->getPrimaryCurrency($user);

        return $expenseTransactions
            ->groupBy('category_id')
            ->map(fn (Collection $group) => [
                'category' => $group->first()->category,
                'total' => (float) $group->sum(fn (Transaction $transaction) => $transaction->currency === $primaryCurrency
                    ? $transaction->amount
                    : $this->currencyService->convert($transaction->amount, $transaction->currency, $primaryCurrency) ?? 0),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }
}
