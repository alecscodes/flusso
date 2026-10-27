<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, SoftDeletes;

    /** {@inheritdoc} */
    protected $fillable = [
        'user_id',
        'name',
        'currency',
        'initial_balance',
        'is_savings',
    ];

    /** {@inheritdoc} */
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'initial_balance' => 'decimal:2',
            'is_savings' => 'boolean',
        ];
    }

    /**
     * Get the user that owns the account.
     */
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the recurring payments for the account.
     */
    /** @return HasMany<RecurringPayment, $this> */
    public function recurringPayments(): HasMany
    {
        return $this->hasMany(RecurringPayment::class);
    }

    /**
     * Get the payments for the account.
     */
    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scope a query to order by name.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeOrderByName($query)
    {
        return $query->orderBy('name');
    }

    /**
     * Scope a query to exclude savings accounts.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeWhereNotSavings($query)
    {
        return $query->where('is_savings', false);
    }

    /**
     * The balance the ledger dictates: initial balance + income - expenses
     * - transfers out + amounts received by transfers in (soft-deleted rows excluded).
     */
    public function ledgerBalance(): string
    {
        $total = Transaction::query()
            ->forAccount($this->id)
            ->selectRaw(
                "coalesce(sum(case when to_account_id = ? then to_amount when type = 'income' then amount else -amount end), 0) as total",
                [$this->id],
            )
            ->value('total');

        return number_format(round((float) $this->initial_balance + (float) $total, 2), 2, '.', '');
    }

    /**
     * The only writer of `accounts.balance`.
     */
    public function recalculateBalance(): void
    {
        $this->forceFill(['balance' => $this->ledgerBalance()])->saveQuietly();
    }
}
