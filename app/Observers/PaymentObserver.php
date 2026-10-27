<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\Payment;

/**
 * An occurrence is always in the currency of its account.
 */
class PaymentObserver
{
    public function saving(Payment $payment): void
    {
        if (blank($payment->currency) || ($payment->isDirty('account_id') && ! $payment->isDirty('currency'))) {
            $payment->currency = Account::withTrashed()->findOrFail($payment->account_id)->currency;
        }
    }
}
