<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentType;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occurrence of a planned payment. It is paid when it links a transaction,
 * skipped when `skipped_at` is set, and `is_overridden` once the owner edited it
 * by hand (the schedule then leaves its amount, date and account alone).
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Payment $payment) {
            if (! $payment->exists || $payment->isDirty('account_id')) {
                $payment->currency = Account::withTrashed()->findOrFail($payment->account_id)->currency;
            }
        });
    }

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'recurring_payment_id',
        'occurrence',
        'type',
        'amount',
        'currency',
        'description',
        'due_date',
        'transaction_id',
        'is_overridden',
        'skipped_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'occurrence' => 'integer',
            'amount' => 'decimal:2',
            'due_date' => DateOnly::class,
            'is_overridden' => 'boolean',
            'skipped_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class)->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->whereNotNull('transaction_id');
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereNull('transaction_id');
    }

    /**
     * Still owed: neither paid nor skipped.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('transaction_id')->whereNull('skipped_at');
    }

    /**
     * What may show up in lists: the account is not archived and the rule not deleted.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereIn('account_id', Account::query()->select('id'))
            ->whereIn('recurring_payment_id', RecurringPayment::query()->select('id'));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->visible()->pending()->where('due_date', '<', now()->toDateString());
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDueBetween(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->where('due_date', '>=', $startDate)->where('due_date', '<=', $endDate);
    }

    public function isPaid(): bool
    {
        return $this->transaction_id !== null;
    }

    public function isPending(): bool
    {
        return $this->transaction_id === null && $this->skipped_at === null;
    }
}
