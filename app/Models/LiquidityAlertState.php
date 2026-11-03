<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remembers what an account was last alerted about (per kind), so an unchanged
 * condition is not notified again until it clears.
 */
class LiquidityAlertState extends Model
{
    /** {@inheritdoc} */
    protected $fillable = [
        'account_id',
        'kind',
        'signature',
        'notified_at',
    ];

    /** {@inheritdoc} */
    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }
}
