<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A date without a time of day: serialized as 'Y-m-d' instead of a UTC timestamp,
 * so it never shifts by a day when the app timezone differs from UTC.
 */
class CalendarDate extends CarbonImmutable
{
    public function toJSON(): string
    {
        return $this->toDateString();
    }
}
