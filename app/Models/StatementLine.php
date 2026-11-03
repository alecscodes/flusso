<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\StatementLineStatus;
use Database\Factories\StatementLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a statement. `amount` is signed (negative is money out) and
 * `parsed_by` says who read it: revolut, csv, pdf or ai.
 */
class StatementLine extends Model
{
    /** @use HasFactory<StatementLineFactory> */
    use HasFactory;

    protected $fillable = [
        'statement_id',
        'user_id',
        'posted_on',
        'amount',
        'currency',
        'description',
        'raw_line',
        'parsed_by',
        'matched_transaction_id',
        'match_status',
    ];

    protected function casts(): array
    {
        return [
            'posted_on' => DateOnly::class,
            'amount' => 'decimal:2',
            'match_status' => StatementLineStatus::class,
        ];
    }

    /** @return BelongsTo<Statement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'matched_transaction_id')->withTrashed();
    }
}
