<?php

use App\Models\User;

test('email verification is off: there are no verification routes and an unverified user gets in', function () {
    expect(Route::has('verification.notice'))->toBeFalse()
        ->and(Route::has('verification.verify'))->toBeFalse()
        ->and(Route::has('verification.send'))->toBeFalse();

    $this->actingAs(User::factory()->unverified()->create())->get(route('dashboard'))->assertOk();
});
