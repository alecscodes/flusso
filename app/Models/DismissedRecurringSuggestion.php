<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\SuggestionDismissalKind;
use Carbon\CarbonImmutable;
use Database\Factories\DismissedRecurringSuggestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring-payment suggestion the owner waved away, keyed by the normalised merchant.
 * "Not recurring" hides it for good, "temporary" until `snooze_until`.
 *
 * @property CarbonImmutable|null $snooze_until
 */
class DismissedRecurringSuggestion extends Model
{
    /** @use HasFactory<DismissedRecurringSuggestionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'normalized_key', 'kind', 'snooze_until'];

    protected function casts(): array
    {
        return [
            'kind' => SuggestionDismissalKind::class,
            'snooze_until' => DateOnly::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the suggestion is hidden on the given day (YYYY-MM-DD).
     */
    public function hidesOn(string $today): bool
    {
        return $this->kind === SuggestionDismissalKind::NotRecurring
            || ($this->snooze_until !== null && $this->snooze_until->toDateString() > $today);
    }
}
