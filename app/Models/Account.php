<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Services\CurrencyService;
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
        'balance',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transactions for the account.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the transfer transactions where this account is the source.
     */
    public function fromTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'from_account_id');
    }

    /**
     * Get the transfer transactions where this account is the destination.
     */
    public function toTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'to_account_id');
    }

    /**
     * Get the recurring payments for the account.
     */
    public function recurringPayments(): HasMany
    {
        return $this->hasMany(RecurringPayment::class);
    }

    /**
     * Get the payments for the account.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scope a query to only include accounts for the given user.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
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
     * Recalculate the account balance by converting all transaction amounts
     * to the account's currency before summing them.
     */
    public function recalculateBalance(): void
    {
        $currencyService = app(CurrencyService::class);

        $incomeTransactions = $this->transactions()
            ->where('type', TransactionType::Income)
            ->get();

        $expenseTransactions = $this->transactions()
            ->where('type', TransactionType::Expense)
            ->get();

        $income = $incomeTransactions->sum(function (Transaction $transaction) use ($currencyService) {
            return $transaction->currency === $this->currency
                ? $transaction->amount
                : $currencyService->convert($transaction->amount, $transaction->currency, $this->currency, $transaction->date) ?? 0;
        });

        $expenses = $expenseTransactions->sum(function (Transaction $transaction) use ($currencyService) {
            return $transaction->currency === $this->currency
                ? $transaction->amount
                : $currencyService->convert($transaction->amount, $transaction->currency, $this->currency, $transaction->date) ?? 0;
        });

        $this->balance = $this->initial_balance + $income - $expenses;
        $this->save();
    }
}
