<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\StatementParseStatus;
use App\Services\Statements\StatementSearchIndex;
use Database\Factories\StatementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * An uploaded bank statement, kept for good. The file lives on the private
 * disk and is only served through an authenticated, user-scoped route.
 *
 * @property int|null $lines_count
 * @property int|null $matched_lines_count
 * @property int|null $unmatched_lines_count
 */
class Statement extends Model
{
    /** @use HasFactory<StatementFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'account_id',
        'institution',
        'account_label',
        'period_start',
        'period_end',
        'original_filename',
        'mime_type',
        'size',
        'sha256',
        'path',
        'extracted_text',
        'parser',
        'lines_truncated',
        'text_truncated',
        'date_order_assumed',
        'parse_status',
        'parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'size' => 'integer',
            'lines_truncated' => 'boolean',
            'text_truncated' => 'boolean',
            'date_order_assumed' => 'boolean',
            'parse_status' => StatementParseStatus::class,
            'parsed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Statement $statement) {
            Storage::disk('local')->delete($statement->path);
            app(StatementSearchIndex::class)->forget($statement);
        });
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

    /** @return HasMany<StatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class);
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
