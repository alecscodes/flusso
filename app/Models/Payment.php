<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentType;
use App\Observers\PaymentObserver;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occurrence of a planned payment. It is paid when it links a transaction,
 * skipped when `skipped_at` is set, and `is_overridden` once the owner edited it
 * by hand (the schedule then leaves its amount, date and account alone).
 *
 * @property int|null $occurrence
 * @property bool $is_overridden
 * @property CarbonImmutable|null $skipped_at
 */
#[ObservedBy(PaymentObserver::class)]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /** @return BelongsTo<RecurringPayment, $this> */
    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class)->withTrashed();
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereNull('transaction_id');
    }

    /**
     * Still owed: neither paid nor skipped.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('transaction_id')->whereNull('skipped_at');
    }

    /**
     * What may show up in lists: the account is not archived and the rule is neither
     * deleted nor paused (see RecurringPayment::isPaused()).
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereIn('account_id', Account::query()->select('id'))
            ->whereIn('recurring_payment_id', RecurringPayment::query()->where('is_active', true)->select('id'));
    }

    /**
     * The Payments page window: everything from the period start on, plus whatever is still owed before it.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeForOverview(Builder $query, string $periodStart): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('due_date', '>=', $periodStart)->orWhere(fn (Builder $query) => $query->pending()));
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->visible()->pending()->where('due_date', '<', now()->toDateString());
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
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
