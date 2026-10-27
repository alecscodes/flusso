<?php

namespace App\Observers;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Transaction;
use RuntimeException;

/**
 * Keeps the ledger invariant: every create/update/delete/restore recalculates the
 * balance of each account the transaction touches (now and before the change).
 */
class TransactionObserver
{
    public function saving(Transaction $transaction): void
    {
        $account = Account::withTrashed()->findOrFail($transaction->account_id);
        $transaction->currency = $account->currency;

        if ($transaction->type !== TransactionType::Transfer) {
            $transaction->to_account_id = null;
            $transaction->to_amount = null;

            return;
        }

        $transaction->category_id = null;

        $toAccount = Account::withTrashed()->findOrFail($transaction->to_account_id);

        // Between accounts of one currency exactly what left arrives.
        if ($toAccount->currency === $account->currency) {
            $transaction->to_amount = $transaction->amount;

            return;
        }

        throw_if(
            $transaction->to_amount === null,
            RuntimeException::class,
            'A cross-currency transfer needs the amount received.',
        );
    }

    public function saved(Transaction $transaction): void
    {
        $this->recalculate($transaction, [
            $transaction->getOriginal('account_id'),
            $transaction->getOriginal('to_account_id'),
        ]);
    }

    /**
     * Fires for soft and force deletes: a payment whose transaction is gone is open again.
     */
    public function deleted(Transaction $transaction): void
    {
        Payment::where('transaction_id', $transaction->id)->update(['transaction_id' => null]);

        $this->recalculate($transaction);
    }

    public function restored(Transaction $transaction): void
    {
        $this->recalculate($transaction);
    }

    /**
     * @param  array<int, int|null>  $previousAccountIds
     */
    private function recalculate(Transaction $transaction, array $previousAccountIds = []): void
    {
        $ids = array_filter([$transaction->account_id, $transaction->to_account_id, ...$previousAccountIds]);

        Account::withTrashed()->whereKey(array_unique($ids))->get()
            ->each(fn (Account $account) => $account->recalculateBalance());
    }
}
