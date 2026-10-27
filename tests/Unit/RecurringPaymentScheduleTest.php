<?php

use App\Models\RecurringPayment;

function rule(array $attributes): RecurringPayment
{
    return new RecurringPayment(['interval_type' => 'months', 'interval_value' => 1, 'start_date' => '2026-01-31', ...$attributes]);
}

test('occurrences are counted from the start date, so month ends do not drift', function () {
    $dates = collect(rule(['installments' => 4])->targetOccurrences())->map->toDateString()->all();

    expect($dates)->toBe([0 => '2026-01-31', 1 => '2026-02-28', 2 => '2026-03-31', 3 => '2026-04-30']);
});

test('an end date or an installment count stops the schedule, whichever the rule has', function () {
    expect(rule(['end_date' => '2026-03-15'])->targetOccurrences())->toHaveCount(2)
        ->and(rule(['installments' => 1])->targetOccurrences())->toHaveCount(1);
});
