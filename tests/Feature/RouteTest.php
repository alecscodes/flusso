<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

// The app's own pages are rendered with data in PageRenderingTest.
test('every settings page renders for the owner', function (string $route) {
    actingAs(User::factory()->create())->get(route($route))->assertSuccessful();
})->with([
    'profile.edit',
    'user-password.edit',
    'appearance.edit',
    'settings.finance.edit',
    'settings.registration.edit',
    'settings.banned-ips.index',
    'settings.connected-apps.index',
]);

test('the currency rate route returns today\'s rate', function () {
    Http::fake(['*' => Http::response(['usd' => ['eur' => 0.9]])]);

    actingAs(User::factory()->create())->get(route('currency.rates', ['from' => 'usd', 'to' => 'eur']))
        ->assertSuccessful()
        ->assertJsonPath('from', 'USD')
        ->assertJsonPath('to', 'EUR')
        ->assertJsonPath('rate', 0.9);
});

test('the currency rate route is a 404 when no rate can be found', function () {
    Http::fake(['*' => Http::response([], 500)]);

    actingAs(User::factory()->create())->get(route('currency.rates', ['from' => 'USD', 'to' => 'XYZ']))->assertNotFound();
});
