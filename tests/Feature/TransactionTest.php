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

test('transactions index filter by type returns only matching transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'income']);

    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'type' => TransactionType::Income,
    ]);
    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::Expense,
    ]);

    $response = actingAs($user)->get(route('transactions.index', ['type' => 'income']));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('transactions.data', 3)
            ->where('transactions.total', 3)
        );
});

test('transactions index filter by account returns only matching transactions', function () {
    $user = User::factory()->create();
    $account1 = Account::factory()->create(['user_id' => $user->id]);
    $account2 = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'account_id' => $account1->id,
        'category_id' => $category->id,
    ]);
    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'account_id' => $account2->id,
        'category_id' => $category->id,
    ]);

    $response = actingAs($user)->get(route('transactions.index', ['account_id' => $account1->id]));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('transactions.data', 2)
            ->where('transactions.total', 2)
        );
});

test('transactions index filter by category returns only matching transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category1 = Category::factory()->create(['user_id' => $user->id]);
    $category2 = Category::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category1->id,
    ]);
    Transaction::factory()->count(4)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category2->id,
    ]);

    $response = actingAs($user)->get(route('transactions.index', ['category_id' => $category1->id]));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('transactions.data', 2)
            ->where('transactions.total', 2)
        );
});

test('transactions index filter by search returns only matching transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Unique grocery shopping',
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Salary payment',
    ]);

    $response = actingAs($user)->get(route('transactions.index', ['search' => 'grocery']));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('transactions.data', 1)
            ->where('transactions.total', 1)
        );
});
