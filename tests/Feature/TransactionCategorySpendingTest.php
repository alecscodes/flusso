<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('filters out transactions without categories when calculating category spending', function () {
    $user = User::factory()->create();

    // Create a category
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Test Category',
        'type' => 'expense',
    ]);

    // Create transactions with and without categories
    Transaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'amount' => 100,
        'category_id' => $category->id,
        'date' => '2024-01-01',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'amount' => 50,
        'category_id' => null, // No category
        'date' => '2024-01-01',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'amount' => 75,
        'category_id' => $category->id,
        'date' => '2024-01-01',
    ]);

    // Make request with date filters to trigger category spending calculation
    $response = $this->actingAs($user)
        ->get('/transactions?date_start=2024-01-01&date_end=2024-01-31');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('categorySpending')
            ->where('categorySpending', fn ($spending) => count($spending) === 1 &&
                isset($spending[0]['category']) &&
                $spending[0]['category']['id'] === $category->id
            )
        );
});

it('returns empty category spending when no transactions have categories', function () {
    $user = User::factory()->create();

    // Create transactions without categories
    Transaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'amount' => 100,
        'category_id' => null,
        'date' => '2024-01-01',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'amount' => 50,
        'category_id' => null,
        'date' => '2024-01-01',
    ]);

    // Make request with date filters
    $response = $this->actingAs($user)
        ->get('/transactions?date_start=2024-01-01&date_end=2024-01-31');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('categorySpending', [])
        );
});
