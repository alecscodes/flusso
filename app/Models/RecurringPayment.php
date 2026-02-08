<?php

namespace App\Models;

use App\Enums\IntervalType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecurringPayment extends Model
{
    /** @use HasFactory<\Database\Factories\RecurringPaymentFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        // Delete unpaid payments when recurring payment is deleted
        static::deleting(function (RecurringPayment $recurringPayment) {
            $recurringPayment->payments()
                ->where('is_paid', false)
                ->delete();
        });
    }

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'name',
        'amount',
        'currency',
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
            'start_date' => 'date',
            'end_date' => 'date',
            'installments' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeNotEnded($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('end_date')
                ->orWhere('end_date', '>=', now()->toDateString());
        });
    }

    public function scopeNotExhausted($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('installments')
                ->orWhereRaw('(SELECT COUNT(*) FROM payments WHERE payments.recurring_payment_id = recurring_payments.id AND payments.deleted_at IS NULL) < recurring_payments.installments');
        });
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function hasEnded(): bool
    {
        if ($this->end_date && $this->end_date->lt(now()->startOfDay())) {
            return true;
        }

        if ($this->installments && $this->payments()->count() >= $this->installments) {
            return true;
        }

        return false;
    }

    public function getNextDueDate(): ?\Carbon\Carbon
    {
        $latestPayment = $this->payments()->latest('due_date')->first();

        if ($latestPayment) {
            return $this->calculateNextDate(\Carbon\Carbon::parse($latestPayment->due_date));
        }

        return \Carbon\Carbon::parse($this->start_date);
    }

    public function calculateNextDate(\Carbon\Carbon $fromDate): \Carbon\Carbon
    {
        $intervalValue = (int) $this->interval_value;

        return match ($this->interval_type) {
            IntervalType::Days => $fromDate->copy()->addDays($intervalValue),
            IntervalType::Weeks => $fromDate->copy()->addWeeks($intervalValue),
            IntervalType::Months => $fromDate->copy()->addMonths($intervalValue),
            IntervalType::Years => $fromDate->copy()->addYears($intervalValue),
        };
    }
}
