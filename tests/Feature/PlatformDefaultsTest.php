<?php

use Carbon\CarbonImmutable;

test('dates are immutable by default', function () {
    expect(now())->toBeInstanceOf(CarbonImmutable::class);

    $tomorrow = now()->addDay();

    expect($tomorrow->isSameDay(now()))->toBeFalse();
});
