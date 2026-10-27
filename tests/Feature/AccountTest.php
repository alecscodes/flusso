<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('user can create an account', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('accounts.store'), [
            'name' => 'Test Account',
            'currency' => 'EUR',
            'balance' => 1000.00,
        ])
        ->assertRedirect(route('accounts.index'));

    expect(Account::where('user_id', $user->id)->count())->toBe(1);
    expect(Account::where('user_id', $user->id)->first())
        ->name->toBe('Test Account')
        ->currency->toBe('EUR')
        ->balance->toBe('1000.00');
});

test('user can update an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->put(route('accounts.update', $account), [
            'name' => 'Updated Account',
            'currency' => 'USD',
            'balance' => 2000.00,
        ])
        ->assertRedirect(route('accounts.index'));

    $account->refresh();
    expect($account->name)->toBe('Updated Account')
        ->and($account->currency)->toBe('USD');
});

test('user can delete an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect(route('accounts.index'));

    expect(Account::find($account->id))->toBeNull();
});

test('the currency of an account with transactions cannot change', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $transaction = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense']);

    $update = fn (string $currency) => actingAs($user)->put(route('accounts.update', $account), ['name' => 'Same', 'currency' => $currency]);

    $update('USD')->assertSessionHasErrors('currency');
    expect($account->fresh()->currency)->toBe('EUR');

    // Same currency and other fields still save; a deleted transaction still counts (it can be restored).
    $update('EUR')->assertSessionHasNoErrors();
    $transaction->delete();
    $update('USD')->assertSessionHasErrors('currency');
});

test('the currency of an account without transactions can change and its planned payments follow', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $rule = plannedPayment($user, ['installments' => 2], $account);

    actingAs($user)->put(route('accounts.update', $account), ['name' => 'Switched', 'currency' => 'RON'])->assertRedirect();

    expect($account->fresh()->currency)->toBe('RON')
        ->and($rule->fresh()->currency)->toBe('RON')
        ->and($rule->payments()->pluck('currency')->unique()->all())->toBe(['RON']);
});
