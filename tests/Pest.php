<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Services\RecurringPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A planned payment (rule) with its occurrences generated, on a fresh EUR account unless given one.
 *
 * @param  array<string, mixed>  $overrides
 */
function plannedPayment(User $user, array $overrides = [], ?Account $account = null): RecurringPayment
{
    $account ??= Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000]);

    return app(RecurringPaymentService::class)->createRecurringPayment($user, [
        'name' => 'Rent',
        'account_id' => $account->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => 'expense'])->id,
        'amount' => 10,
        'interval_type' => 'months',
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        ...$overrides,
    ]);
}
