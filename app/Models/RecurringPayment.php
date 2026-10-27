<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\IntervalType;
use App\Enums\PlannedPaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RecurringPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A planned payment: a rule that stands for numbered occurrences (`payments`).
 * Occurrence n falls on `start_date + n * interval`; it ends never, on a date,
 * or after a number of installments. "Once" is a single installment.
 * Currency follows the account.
 */
class RecurringPayment extends Model
{
    /** @use HasFactory<RecurringPaymentFactory> */
    use HasFactory, SoftDeletes;

    /** How far ahead rules without an installment count are scheduled. */
    private const HORIZON_MONTHS = 12;

    protected static function booted(): void
    {
        static::saving(function (RecurringPayment $rule) {
            if (! $rule->exists || $rule->isDirty('account_id')) {
                $rule->currency = Account::withTrashed()->findOrFail($rule->account_id)->currency;
            }
        });

        // A deleted rule keeps its paid history but not what is still owed.
        static::deleting(fn (RecurringPayment $rule) => $rule->payments()->unpaid()->delete());
    }

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'name',
        'amount',
        'interval_type',
        'interval_value',
        'start_date',
        'end_date',
        'installments',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'interval_type' => IntervalType::class,
            'interval_value' => 'integer',
            'amount' => 'decimal:2',
            'start_date' => DateOnly::class,
            'end_date' => DateOnly::class,
            'installments' => 'integer',
            'is_active' => 'boolean',
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

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The date of occurrence n, always counted from the start date so month ends never drift.
     */
    public function dateFor(int $n): CarbonImmutable
    {
        $steps = $n * max(1, (int) $this->interval_value);

        return match ($this->interval_type) {
            IntervalType::Days => $this->start_date->addDays($steps),
            IntervalType::Weeks => $this->start_date->addWeeks($steps),
            IntervalType::Months => $this->start_date->addMonthsNoOverflow($steps),
            IntervalType::Years => $this->start_date->addYearsNoOverflow($steps),
        };
    }

    /**
     * The occurrences that should exist, by number. Rules without an installment
     * count are scheduled a year ahead; the daily sync extends them.
     *
     * @return array<int, CarbonImmutable>
     */
    public function targetOccurrences(): array
    {
        $horizon = now()->addMonthsNoOverflow(self::HORIZON_MONTHS)->toDateString();
        $dates = [];

        for ($n = 0; $this->installments === null || $n < $this->installments; $n++) {
            $date = $this->dateFor($n);

            if (($this->end_date && $date->gt($this->end_date)) || ($this->installments === null && $date->toDateString() > $horizon)) {
                break;
            }

            $dates[$n] = $date;
        }

        return $dates;
    }

    /**
     * Not active, or its account is archived. Payment::scopeVisible() mirrors this in SQL.
     */
    public function isPaused(): bool
    {
        return ! $this->is_active || $this->account->trashed();
    }

    /**
     * paused | finished (nothing left to pay and no occurrence left to schedule, ever) | active.
     */
    public function getStatusAttribute(): PlannedPaymentStatus
    {
        $this->loadMissing(['payments', 'account']);

        if ($this->isPaused()) {
            return PlannedPaymentStatus::Paused;
        }

        if ($this->payments->contains(fn (Payment $payment) => $payment->isPending())) {
            return PlannedPaymentStatus::Active;
        }

        $next = ($this->payments->max('occurrence') ?? -1) + 1;

        $hasMore = ($this->installments === null || $next < $this->installments)
            && ($this->end_date === null || $this->dateFor($next)->lte($this->end_date));

        return $hasMore ? PlannedPaymentStatus::Active : PlannedPaymentStatus::Finished;
    }
}
