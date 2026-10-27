<?php

use App\Models\Account;
use App\Models\CurrencyExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\actingAs;

test('a missing rate is looked up and warned about once per request, not once per conversion', function () {
    Http::fake(['*' => Http::response([], 500)]);
    Log::spy();
    $currency = app(CurrencyService::class);

    expect($currency->getRate('EUR', 'USD'))->toBeNull()
        ->and($currency->convert(10, 'EUR', 'USD'))->toBeNull()
        ->and($currency->getRate('EUR', 'USD'))->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'No exchange rate available')->once();
});

test('a stale stored rate may be shown but is never used for a write', function () {
    Http::fake(['*' => Http::response([], 500)]);
    CurrencyExchangeRate::create(['from_currency' => 'eur', 'to_currency' => 'ron', 'rate' => 4.9, 'date' => '2020-01-01']);
    $currency = app(CurrencyService::class);

    expect($currency->getRate('EUR', 'RON'))->toBe(4.9)
        ->and($currency->getRate('EUR', 'RON', allowStale: false))->toBeNull();
});

test('a cross-currency transfer without the amount received is refused when only an old rate exists', function () {
    Http::fake(['*' => Http::response([], 500)]);
    CurrencyExchangeRate::create(['from_currency' => 'eur', 'to_currency' => 'ron', 'rate' => 4.9, 'date' => '2020-01-01']);
    $user = User::factory()->create();
    $eur = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 100]);
    $ron = Account::factory()->create(['user_id' => $user->id, 'currency' => 'RON', 'balance' => 0]);

    actingAs($user)->post(route('transactions.store'), [
        'type' => 'transfer', 'account_id' => $eur->id, 'to_account_id' => $ron->id, 'amount' => 20, 'date' => now()->toDateString(),
    ])->assertSessionHasErrors('to_amount');

    expect(Transaction::count())->toBe(0);
});

test('the primary currency is resolved per user, not once per request', function () {
    $currency = app(CurrencyService::class);
    $euroUser = User::factory()->create();
    $leuUser = User::factory()->create();
    Account::factory()->create(['user_id' => $euroUser->id, 'currency' => 'EUR']);
    Account::factory()->create(['user_id' => $leuUser->id, 'currency' => 'RON']);

    expect($currency->getPrimaryCurrency($euroUser))->toBe('EUR')
        ->and($currency->getPrimaryCurrency($leuUser))->toBe('RON');
});

test('the currency service lives for one request only', function () {
    $first = app(CurrencyService::class);

    expect(app(CurrencyService::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(CurrencyService::class))->not->toBe($first);
});

test('when the rate API is down the latest stored rate is used and the failure is remembered', function () {
    Http::fake(['*' => Http::response([], 500)]);
    $currency = app(CurrencyService::class);

    CurrencyExchangeRate::create(['from_currency' => 'eur', 'to_currency' => 'ron', 'rate' => 4.9, 'date' => '2020-01-01']);
    CurrencyExchangeRate::create(['from_currency' => 'eur', 'to_currency' => 'ron', 'rate' => 5.0, 'date' => '2020-02-01']);

    expect($currency->getRate('EUR', 'RON'))->toBe(5.0);
    $calls = count(Http::recorded());
    expect($calls)->toBe(2)
        ->and($currency->getRate('EUR', 'RON'))->toBe(5.0)
        ->and(count(Http::recorded()))->toBe($calls);

    // Nothing stored either: still only one attempt per outage.
    expect($currency->getRate('EUR', 'USD'))->toBeNull();
    $calls = count(Http::recorded());
    expect($currency->getRate('EUR', 'USD'))->toBeNull()
        ->and(count(Http::recorded()))->toBe($calls);
});
