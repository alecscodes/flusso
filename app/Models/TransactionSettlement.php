<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The part of an income that settles an expense (an advance), in both transactions' own currency.
 *
 * A soft-deleted transaction keeps its rows (restoring it brings them back), but they
 * count for nothing: every sum or check over this table goes through the `live` scope.
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

    /**
     * Only settlements whose income and expense are both not deleted.
     *
     * @param  Builder<TransactionSettlement>  $query
     * @return Builder<TransactionSettlement>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereIn('transaction_settlements.income_transaction_id', Transaction::query()->select('id'))
            ->whereIn('transaction_settlements.expense_transaction_id', Transaction::query()->select('id'));
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
