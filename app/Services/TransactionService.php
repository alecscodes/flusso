<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            ->with(['account', 'category', 'linkedTransaction', 'from_account', 'to_account'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        return $limit ? $query->limit($limit)->get() : $query->get();
    }

    /**
     * Get transactions for a specific period.
     */
    public function getTransactionsForPeriod(User $user, Carbon $startDate, Carbon $endDate): Collection
    {
        return $user->transactions()
            ->with(['account', 'category', 'from_account', 'to_account'])
            ->inPeriod($startDate->toDateString(), $endDate->toDateString())
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Create a new transaction.
     */
    public function createTransaction(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data) {
            $account = $user->accounts()->findOrFail($data['account_id']);

            $transaction = $user->transactions()->create([
                'account_id' => $account->id,
                'category_id' => $data['category_id'] ?? null,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? $account->currency,
                'description' => $data['description'] ?? null,
                'date' => $data['date'] ?? now()->toDateString(),
                'recurring_payment_id' => $data['recurring_payment_id'] ?? null,
            ]);

            if (isset($data['files']) && is_array($data['files'])) {
                $this->fileUploadService->handleTransactionFiles($transaction, $data['files']);
            }

            $this->applyTransactionToBalance($account, $transaction);

            return $transaction->load(['account', 'category', 'files']);
        });
    }

    /**
     * Update an existing transaction.
     */
    public function updateTransaction(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $oldAccount = $transaction->account;
            $newAccount = Account::findOrFail($data['account_id'] ?? $transaction->account_id);

            $this->revertTransactionFromBalance($oldAccount, $transaction);

            $transaction->update([
                'account_id' => $newAccount->id,
                'category_id' => $data['category_id'] ?? $transaction->category_id,
                'type' => $data['type'] ?? $transaction->type,
                'amount' => $data['amount'] ?? $transaction->amount,
                'currency' => $data['currency'] ?? $transaction->currency,
                'description' => $data['description'] ?? $transaction->description,
                'date' => $data['date'] ?? $transaction->date,
            ]);

            if (isset($data['files']) && is_array($data['files'])) {
                $this->fileUploadService->handleTransactionFiles($transaction, $data['files']);
            }

            $transaction->refresh();
            $this->applyTransactionToBalance($newAccount, $transaction);

            return $transaction->load(['account', 'category', 'files']);
        });
    }

    /**
     * Delete a transaction and revert its effects.
     */
    public function deleteTransaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            if ($transaction->isTransfer()) {
                $this->revertTransferBalances($transaction);
                $linkedTransaction = $transaction->linkedTransaction;
                $linkedTransaction?->delete();
                $transaction->delete();

                return;
            }

            $this->revertTransactionFromBalance($transaction->account, $transaction);
            $transaction->delete();
        });
    }

    /**
     * Revert balances for transfer transactions.
     */
    private function revertTransferBalances(Transaction $transaction): void
    {
        $fromAccount = $transaction->from_account_id ? Account::find($transaction->from_account_id) : null;
        $toAccount = $transaction->to_account_id ? Account::find($transaction->to_account_id) : null;

        if (! $fromAccount || ! $toAccount) {
            return;
        }

        $linkedTransaction = $transaction->linkedTransaction;

        if ($linkedTransaction) {
            $outgoingAmount = $transaction->account_id === $transaction->from_account_id
                ? $transaction->amount
                : $linkedTransaction->amount;
            $incomingAmount = $transaction->account_id === $transaction->to_account_id
                ? $transaction->amount
                : $linkedTransaction->amount;

            $fromAccount->increment('balance', $outgoingAmount);
            $toAccount->decrement('balance', $incomingAmount);
        } else {
            if ($transaction->account_id === $transaction->from_account_id) {
                $fromAccount->increment('balance', $transaction->amount);
            } else {
                $toAccount->decrement('balance', $transaction->amount);
            }
        }
    }

    /**
     * Create a transfer between accounts.
     */
    public function createTransfer(User $user, array $data): array
    {
        $fromAccount = $user->accounts()->findOrFail($data['from_account_id']);
        $toAccount = $user->accounts()->findOrFail($data['to_account_id']);

        if ($fromAccount->id === $toAccount->id) {
            throw new \InvalidArgumentException('Cannot transfer to the same account');
        }

        $amount = (float) $data['amount'];
        $exchangeRate = $data['exchange_rate'] ?? null;
        $date = $data['date'] ?? now()->toDateString();

        if ($exchangeRate === null && $fromAccount->currency !== $toAccount->currency) {
            $exchangeRate = $this->currencyService->getRate(
                $fromAccount->currency,
                $toAccount->currency,
                $date
            );

            if ($exchangeRate === null) {
                throw new \RuntimeException('Unable to fetch exchange rate');
            }
        }

        $exchangeRate = $exchangeRate ?? 1.0;
        $destinationAmount = $amount * $exchangeRate;

        return DB::transaction(function () use (
            $user,
            $fromAccount,
            $toAccount,
            $amount,
            $destinationAmount,
            $exchangeRate,
            $data,
            $date
        ) {
            $description = $data['description'] ?? null;

            $outgoingTransaction = $user->transactions()->create([
                'account_id' => $fromAccount->id,
                'type' => TransactionType::Transfer,
                'amount' => $amount,
                'currency' => $fromAccount->currency,
                'description' => $description ?? "Transfer to {$toAccount->name}",
                'date' => $date,
                'exchange_rate' => $exchangeRate,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
            ]);

            $incomingTransaction = $user->transactions()->create([
                'account_id' => $toAccount->id,
                'type' => TransactionType::Transfer,
                'amount' => $destinationAmount,
                'currency' => $toAccount->currency,
                'description' => $description ?? "Transfer from {$fromAccount->name}",
                'date' => $date,
                'exchange_rate' => $exchangeRate,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'linked_transaction_id' => $outgoingTransaction->id,
            ]);

            $outgoingTransaction->update(['linked_transaction_id' => $incomingTransaction->id]);

            $fromAccount->decrement('balance', $amount);
            $toAccount->increment('balance', $destinationAmount);

            return [
                'outgoing' => $outgoingTransaction->load(['account', 'linkedTransaction']),
                'incoming' => $incomingTransaction->load(['account', 'linkedTransaction']),
            ];
        });
    }

    /**
     * Get transaction summary for a period with currency conversion.
     */
    public function getTransactionSummaryForPeriod(User $user, Carbon $startDate, Carbon $endDate): array
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
     * Get category spending for a period with currency conversion.
     */
    public function getCategorySpendingForPeriod(User $user, Carbon $startDate, Carbon $endDate, int $limit = 10): Collection
    {
        $transactions = $this->getTransactionsForPeriod($user, $startDate, $endDate);
        $expenseTransactions = $transactions->where('type', TransactionType::Expense);

        $primaryCurrency = $this->currencyService->getPrimaryCurrency($user);

        $convertedTransactions = $expenseTransactions->map(function (Transaction $transaction) use ($primaryCurrency) {
            $convertedAmount = $transaction->currency === $primaryCurrency
                ? $transaction->amount
                : $this->currencyService->convert($transaction->amount, $transaction->currency, $primaryCurrency, $transaction->date) ?? 0;

            return (object) [
                'category_id' => $transaction->category_id,
                'category' => $transaction->category,
                'converted_amount' => $convertedAmount,
                'original_transaction' => $transaction,
            ];
        });

        return $convertedTransactions
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->map(fn ($group) => [
                'category' => $group->first()->category,
                'total' => (float) $group->sum('converted_amount'),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }

    /**
     * Apply transaction effects to account balance.
     */
    private function applyTransactionToBalance(Account $account, Transaction $transaction): void
    {
        match ($transaction->type) {
            TransactionType::Income => $account->increment('balance', $transaction->amount),
            TransactionType::Expense => $account->decrement('balance', $transaction->amount),
            TransactionType::Transfer => null,
        };
    }

    /**
     * Revert transaction effects from account balance.
     */
    private function revertTransactionFromBalance(Account $account, Transaction $transaction): void
    {
        match ($transaction->type) {
            TransactionType::Income => $account->decrement('balance', $transaction->amount),
            TransactionType::Expense => $account->increment('balance', $transaction->amount),
            TransactionType::Transfer => null,
        };
    }
}
