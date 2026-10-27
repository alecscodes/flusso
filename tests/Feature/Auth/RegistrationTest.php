<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Register')
            ->has('passwordRules'));
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('an email typed with capitals is registered in lowercase', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'Test@Example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(User::sole()->email)->toBe('test@example.com');
});

test('the password rules are shared with every page, not passed one by one', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('passwordRules', Password::defaults()->toPasswordRulesString()));
});

test('the registration rules themselves require a lowercase email', function () {
    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Test User', 'email' => 'Test@Example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ]))->toThrow(ValidationException::class);
});
