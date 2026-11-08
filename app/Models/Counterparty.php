<?php

namespace App\Models;

use Database\Factories\CounterpartyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person the owner pays for (or who pays the owner back): the other side of advances and settlements.
 */
class Counterparty extends Model
{
    /** @use HasFactory<CounterpartyFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
