<?php

namespace App\Casts;

use App\Support\CalendarDate;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar date stored as 'Y-m-d' (never with a time part), so string
 * comparisons against 'Y-m-d' bounds include the whole last day.
 *
 * @implements CastsAttributes<CalendarDate|null, mixed>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CalendarDate
    {
        return $value === null ? null : CalendarDate::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : CalendarDate::parse($value)->toDateString();
    }
}
