<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('creating income transaction updates account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
    ]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'income',
    ]);

    actingAs($user)
        ->post(route('transactions.store'), [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => TransactionType::Income->value,
            'amount' => 500.00,
            'currency' => $account->currency,
            'date' => now()->toDateString(),
        ])
        ->assertRedirect(route('transactions.index'));

    $account->refresh();
    expect($account->balance)->toBe('1500.00');
});

test('creating expense transaction updates account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
    ]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    actingAs($user)
        ->post(route('transactions.store'), [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => TransactionType::Expense->value,
            'amount' => 300.00,
            'currency' => $account->currency,
            'date' => now()->toDateString(),
        ])
        ->assertRedirect(route('transactions.index'));

    $account->refresh();
    expect($account->balance)->toBe('700.00');
});

test('deleting transaction reverts account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
    ]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'income',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'type' => TransactionType::Income,
        'amount' => 500.00,
    ]);

    $account->increment('balance', 500.00);
    expect($account->fresh()->balance)->toBe('1500.00');

    actingAs($user)
        ->delete(route('transactions.destroy', $transaction))
        ->assertRedirect(route('transactions.index'));

    $account->refresh();
    expect($account->balance)->toBe('1000.00');
});
