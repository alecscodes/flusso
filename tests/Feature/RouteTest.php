<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('dashboard route is accessible', function () {
    $user = User::factory()->create();
    actingAs($user)->get(route('dashboard'))->assertSuccessful();
});

test('accounts routes are accessible', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)->get(route('accounts.index'))->assertSuccessful();
    actingAs($user)->get(route('accounts.show', $account))->assertSuccessful();
});

test('categories routes are accessible', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    actingAs($user)->get(route('categories.index'))->assertSuccessful();
    actingAs($user)->get(route('categories.show', $category))->assertSuccessful();
});

test('transactions routes are accessible', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);

    actingAs($user)->get(route('transactions.index'))->assertSuccessful();
    actingAs($user)->get(route('transactions.show', $transaction))->assertSuccessful();
});

test('recurring payments routes are accessible', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $recurringPayment = RecurringPayment::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    actingAs($user)->get(route('recurring-payments.index'))->assertSuccessful();
    actingAs($user)->get(route('recurring-payments.show', $recurringPayment))->assertSuccessful();
});

test('payments route is accessible', function () {
    $user = User::factory()->create();
    actingAs($user)->get(route('payments.index'))->assertSuccessful();
});

test('settings routes are accessible', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('profile.edit'))->assertSuccessful();
    actingAs($user)->get(route('user-password.edit'))->assertSuccessful();
    actingAs($user)->get(route('appearance.edit'))->assertSuccessful();
    actingAs($user)->get(route('settings.finance.edit'))->assertSuccessful();
    // Two-factor route may require password confirmation, so we check for redirect or success
    $response = actingAs($user)->get(route('two-factor.show'));
    expect($response->status())->toBeIn([200, 302]);
});

test('currency rates route is accessible', function () {
    $user = User::factory()->create();
    // Currency rates route requires query parameters
    actingAs($user)->get(route('currency.rates', ['from' => 'USD', 'to' => 'EUR']))->assertSuccessful();
});
