<?php

use App\Models\Account;
use App\Models\User;
use App\Services\AccountService;

use function Pest\Laravel\actingAs;

test('user can create a savings account', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('accounts.store'), [
            'name' => 'Emergency Fund',
            'currency' => 'EUR',
            'balance' => 5000.00,
            'is_savings' => true,
        ])
        ->assertRedirect(route('accounts.index'));

    expect(Account::where('user_id', $user->id)->first())
        ->name->toBe('Emergency Fund')
        ->currency->toBe('EUR')
        ->balance->toBe('5000.00')
        ->is_savings->toBe(true);
});

test('user can update account to savings', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Checking Account',
        'currency' => 'USD',
        'balance' => 1000.00,
        'is_savings' => false,
    ]);

    actingAs($user)
        ->put(route('accounts.update', $account), [
            'name' => 'Updated Checking Account',
            'currency' => 'USD',
            'is_savings' => true,
        ])
        ->assertRedirect(route('accounts.index'));

    expect($account->fresh())
        ->name->toBe('Updated Checking Account')
        ->is_savings->toBe(true);
});

test('available balance excludes savings accounts', function () {
    $user = User::factory()->create();

    $regularAccount = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 2000.00,
        'currency' => 'EUR',
        'is_savings' => false,
    ]);

    $savingsAccount = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 5000.00,
        'currency' => 'EUR',
        'is_savings' => true,
    ]);

    $accountService = app(AccountService::class);

    $availableBalance = $accountService->getTotalBalanceInPrimaryCurrency($user);
    $totalBalance = $accountService->getFullBalanceInPrimaryCurrency($user);
    $savingsBalance = $accountService->getSavingsBalanceInPrimaryCurrency($user);

    expect($availableBalance)->toBe(2000.00);
    expect($totalBalance)->toBe(7000.00);
    expect($savingsBalance)->toBe(5000.00);
});

test('whereNotSavings scope excludes savings accounts', function () {
    $user = User::factory()->create();

    Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
        'is_savings' => false,
    ]);

    Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 2000.00,
        'is_savings' => true,
    ]);

    $nonSavingsAccounts = $user->accounts()->whereNotSavings()->get();
    $allAccounts = $user->accounts()->get();

    expect($nonSavingsAccounts)->toHaveCount(1);
    expect($allAccounts)->toHaveCount(2);
    expect($nonSavingsAccounts->first()->is_savings)->toBe(false);
});
