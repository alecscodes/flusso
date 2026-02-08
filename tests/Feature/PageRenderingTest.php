<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('dashboard page renders with data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);

    actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('accounts')
            ->has('period')
            ->has('summary')
            ->has('paymentSummary')
        );
});

test('accounts index page renders with data', function () {
    $user = User::factory()->create();
    Account::factory()->count(3)->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('accounts.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 3)
        );
});

test('account show page renders with data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->count(5)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    actingAs($user)
        ->get(route('accounts.show', $account))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account')
        );
});

test('categories index page renders with data', function () {
    $user = User::factory()->create();
    Category::factory()->count(5)->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('categories.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Categories/Index')
            ->has('categories', 5)
        );
});

test('transactions index page renders with data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->count(10)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    actingAs($user)
        ->get(route('transactions.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('transactions.data', 10)
            ->has('accounts')
            ->has('categories')
        );
});

test('transaction show page renders with data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    actingAs($user)
        ->get(route('transactions.show', $transaction))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Show')
            ->has('transaction')
        );
});

test('recurring payments index page renders with data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    RecurringPayment::factory()->count(3)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    actingAs($user)
        ->get(route('recurring-payments.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('RecurringPayments/Index')
            ->has('recurringPayments', 3)
            ->has('accounts')
            ->has('categories')
        );
});

test('recurring payment show page renders with data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $recurringPayment = RecurringPayment::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    actingAs($user)
        ->get(route('recurring-payments.show', $recurringPayment))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('RecurringPayments/Show')
            ->has('recurringPayment')
            ->has('statistics')
        );
});

test('payments index page renders with data', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    Payment::factory()->count(5)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    actingAs($user)
        ->get(route('payments.index'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Payments/Index')
            ->has('payments')
            ->has('overduePayments')
            ->has('upcomingPayments')
            ->has('totals')
        );
});

test('all settings pages render correctly', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('profile.edit'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Settings/Profile'));

    actingAs($user)
        ->get(route('user-password.edit'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Settings/Password'));

    actingAs($user)
        ->get(route('appearance.edit'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Settings/Appearance'));

    actingAs($user)
        ->get(route('settings.finance.edit'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Settings/Finance'));
});

test('all auth pages render correctly', function () {
    get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Auth/Login'));

    get(route('register'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Auth/Register'));

    get(route('password.request'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
});
