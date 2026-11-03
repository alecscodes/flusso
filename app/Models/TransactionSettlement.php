<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of an income that settles an expense (an advance), in both transactions' own currency.
 *
 * Rows go with either transaction as soon as it is deleted (see the TransactionObserver),
 * so every sum over this table only ever covers live transactions.
 */
class TransactionSettlement extends Model
{
    protected $fillable = [
        'income_transaction_id',
        'expense_transaction_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function income(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'income_transaction_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'expense_transaction_id');
    }
}
