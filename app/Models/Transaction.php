<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\TransactionType;
use App\Observers\TransactionObserver;
use Carbon\CarbonInterface;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

/**
 * A transfer is ONE row: `account_id` is the source (amount in its currency),
 * `to_account_id` the destination and `to_amount` what arrived there (in the
 * destination's currency).
 */
#[ObservedBy(TransactionObserver::class)]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
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
        'date',
        'to_account_id',
        'to_amount',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'date' => DateOnly::class,
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Transaction $transaction) {
            // Delete all associated files from storage and database
            $transaction->files->each(function (TransactionFile $file) {
                Storage::disk('local')->delete($file->path);
                $file->delete();
            });
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function recurringPayment(): BelongsTo
    {
        return $this->belongsTo(RecurringPayment::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(TransactionFile::class);
    }

    public function scopeIncome($query)
    {
        return $query->where('type', TransactionType::Income);
    }

    public function scopeExpense($query)
    {
        return $query->where('type', TransactionType::Expense);
    }

    public function scopeTransfer($query)
    {
        return $query->where('type', TransactionType::Transfer);
    }

    /**
     * Transactions that touch the account: its own rows and incoming transfers.
     */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('account_id', $accountId)
            ->orWhere('to_account_id', $accountId));
    }

    public function scopeForCategory($query, int $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    /**
     * Both bounds are inclusive; dates are compared as 'Y-m-d' strings.
     */
    public function scopeInPeriod(Builder $query, CarbonInterface|string|null $startDate, CarbonInterface|string|null $endDate): Builder
    {
        return $query
            ->when($startDate, fn (Builder $q) => $q->where('date', '>=', Date::parse($startDate)->toDateString()))
            ->when($endDate, fn (Builder $q) => $q->where('date', '<=', Date::parse($endDate)->toDateString()));
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isTransfer(): bool
    {
        return $this->type === TransactionType::Transfer;
    }

    public function isIncome(): bool
    {
        return $this->type === TransactionType::Income;
    }

    public function isExpense(): bool
    {
        return $this->type === TransactionType::Expense;
    }
}
