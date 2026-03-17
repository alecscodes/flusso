<?php

namespace App\Models;

use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'recurring_payment_id',
        'type',
        'amount',
        'currency',
        'description',
        'due_date',
        'is_paid',
        'paid_at',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'is_paid' => 'boolean',
            'paid_at' => 'datetime',
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

    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopePaid($query)
    {
        return $query->where('is_paid', true);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('is_paid', false);
    }

    public function scopeForRecurringPayment($query, int $recurringPaymentId)
    {
        return $query->where('recurring_payment_id', $recurringPaymentId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDueBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('due_date', [$startDate, $endDate]);
    }

    public function scopeOverdue($query)
    {
        return $query->unpaid()->where('due_date', '<', now()->toDateString());
    }

    public function scopeUpcoming($query)
    {
        return $query->unpaid()->where('due_date', '>=', now()->toDateString());
    }

    public function isPaid(): bool
    {
        return $this->is_paid;
    }

    public function isOverdue(): bool
    {
        return ! $this->is_paid && $this->due_date->lt(now()->startOfDay());
    }

    public function isIncome(): bool
    {
        return $this->type === PaymentType::Income;
    }

    public function isExpense(): bool
    {
        return $this->type === PaymentType::Expense;
    }
}
