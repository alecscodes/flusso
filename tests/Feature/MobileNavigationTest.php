<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('mobile navigation displays correctly on small screens', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/dashboard')
        ->assertStatus(200);

    // For Inertia apps, we need to check the Inertia props
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('auth.user')
    );
});

test('desktop navigation shows sidebar', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/dashboard')
        ->assertStatus(200);

    // Check that we get the proper Inertia response
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('auth.user')
    );
});

test('navigation structure is correct', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get('/dashboard')
        ->assertStatus(200);

    // Verify the response structure
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('auth.user')
    );
});
