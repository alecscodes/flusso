<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('users cannot see other users accounts', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    Account::factory()->create(['user_id' => $user1->id]);
    Account::factory()->create(['user_id' => $user2->id]);

    actingAs($user1)
        ->get(route('accounts.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 1)
        );
});

test('users cannot access other users categories', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $category1 = Category::factory()->create(['user_id' => $user1->id]);
    $category2 = Category::factory()->create(['user_id' => $user2->id]);

    actingAs($user1)
        ->get(route('categories.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Categories/Index')
            ->has('categories', 1)
        );

    actingAs($user1)
        ->get(route('categories.show', $category2))
        ->assertForbidden();
});

test('users cannot see other users transactions', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $account1 = Account::factory()->create(['user_id' => $user1->id]);
    $account2 = Account::factory()->create(['user_id' => $user2->id]);

    Transaction::factory()->create(['user_id' => $user1->id, 'account_id' => $account1->id]);
    Transaction::factory()->create(['user_id' => $user2->id, 'account_id' => $account2->id]);

    actingAs($user1)
        ->get(route('transactions.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('transactions.data', 1)
        );
});
