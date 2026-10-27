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

    /**
     * The transaction list filters, shared by the Transactions page and the MCP search.
     *
     * @param  array{search?: string|null, account_id?: int|string|null, category_id?: int|string|null, type?: string|null, date_start?: string|null, date_end?: string|null}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(is_string($filters['search'] ?? null) && $filters['search'] !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($filters) {
                $q->where('description', 'like', '%'.$filters['search'].'%');

                if (is_numeric($filters['search'])) {
                    $q->orWhere('amount', $filters['search'])->orWhere('to_amount', $filters['search']);
                }
            }))
            ->when(filled($filters['account_id'] ?? null), fn (Builder $q) => $q->forAccount((int) $filters['account_id']))
            ->when(filled($filters['category_id'] ?? null), fn (Builder $q) => $q->forCategory((int) $filters['category_id']))
            ->when(in_array($filters['type'] ?? null, array_column(TransactionType::cases(), 'value'), true), fn (Builder $q) => $q->where('type', $filters['type']))
            ->inPeriod($filters['date_start'] ?? null, $filters['date_end'] ?? null);
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
