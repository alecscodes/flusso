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
        private AccountService $accountService
    ) {}

    public function getTransactionsForUser(User $user, ?int $limit = null): Collection
    {
        $query = $user->transactions()
            ->with(['account', 'category', 'linkedTransaction'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function getTransactionsForPeriod(User $user, Carbon $startDate, Carbon $endDate): Collection
    {
        return $user->transactions()
            ->with(['account', 'category'])
            ->inPeriod($startDate->toDateString(), $endDate->toDateString())
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

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

            $this->applyTransactionToBalance($account, $transaction);

            return $transaction->load(['account', 'category']);
        });
    }

    public function updateTransaction(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $oldAccount = $transaction->account;
            $newAccountId = $data['account_id'] ?? $transaction->account_id;
            $newAccount = Account::findOrFail($newAccountId);

            $this->revertTransactionFromBalance($oldAccount, $transaction);

            $transaction->update([
                'account_id' => $newAccountId,
                'category_id' => $data['category_id'] ?? $transaction->category_id,
                'type' => $data['type'] ?? $transaction->type,
                'amount' => $data['amount'] ?? $transaction->amount,
                'currency' => $data['currency'] ?? $transaction->currency,
                'description' => $data['description'] ?? $transaction->description,
                'date' => $data['date'] ?? $transaction->date,
            ]);

            $transaction->refresh();
            $this->applyTransactionToBalance($newAccount, $transaction);

            return $transaction->load(['account', 'category']);
        });
    }

    public function deleteTransaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $account = $transaction->account;

            if ($transaction->isTransfer()) {
                $this->revertTransferBalances($transaction);
                $linkedTransaction = $transaction->linkedTransaction;
                if ($linkedTransaction) {
                    $linkedTransaction->delete();
                }
                $transaction->delete();

                return;
            }

            $this->revertTransactionFromBalance($account, $transaction);
            $transaction->delete();
        });
    }

    private function revertTransferBalances(Transaction $transaction): void
    {
        if (! $transaction->from_account_id || ! $transaction->to_account_id) {
            return;
        }

        $fromAccount = Account::find($transaction->from_account_id);
        $toAccount = Account::find($transaction->to_account_id);

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

    public function getTransactionSummaryForPeriod(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $transactions = $this->getTransactionsForPeriod($user, $startDate, $endDate);

        $income = $transactions
            ->where('type', TransactionType::Income)
            ->sum('amount');

        $expenses = $transactions
            ->where('type', TransactionType::Expense)
            ->sum('amount');

        return [
            'income' => (float) $income,
            'expenses' => (float) $expenses,
            'net' => (float) ($income - $expenses),
            'transaction_count' => $transactions->count(),
        ];
    }

    public function getCategorySpendingForPeriod(User $user, Carbon $startDate, Carbon $endDate, int $limit = 10): Collection
    {
        $transactions = $this->getTransactionsForPeriod($user, $startDate, $endDate);

        return $transactions
            ->where('type', TransactionType::Expense)
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->map(fn ($group) => [
                'category' => $group->first()->category,
                'total' => (float) $group->sum('amount'),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }

    private function applyTransactionToBalance(Account $account, Transaction $transaction): void
    {
        match ($transaction->type) {
            TransactionType::Income => $account->increment('balance', $transaction->amount),
            TransactionType::Expense => $account->decrement('balance', $transaction->amount),
            TransactionType::Transfer => null,
        };
    }

    private function revertTransactionFromBalance(Account $account, Transaction $transaction): void
    {
        match ($transaction->type) {
            TransactionType::Income => $account->decrement('balance', $transaction->amount),
            TransactionType::Expense => $account->increment('balance', $transaction->amount),
            TransactionType::Transfer => null,
        };
    }
}
