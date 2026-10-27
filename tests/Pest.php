<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Services\RecurringPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature', 'Unit');

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

/**
 * The form input of a planned payment: a new rule on a fresh EUR account, or, given a rule,
 * its current settings (to send an edit that changes only the overrides).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function plannedPaymentInput(User|RecurringPayment $for, array $overrides = []): array
{
    if ($for instanceof RecurringPayment) {
        return [
            'name' => $for->name,
            'account_id' => $for->account_id,
            'category_id' => $for->category_id,
            'amount' => $for->amount,
            'interval_type' => $for->interval_type->value,
            'interval_value' => $for->interval_value,
            'start_date' => $for->start_date->toDateString(),
            ...array_filter(['end_date' => $for->end_date?->toDateString(), 'installments' => $for->installments], fn ($value) => $value !== null),
            ...$overrides,
        ];
    }

    return [
        'name' => 'Netflix',
        'account_id' => Account::factory()->create(['user_id' => $for->id, 'currency' => 'EUR'])->id,
        'category_id' => Category::factory()->create(['user_id' => $for->id, 'type' => 'expense'])->id,
        'amount' => 15.99,
        'interval_type' => 'months',
        'interval_value' => 1,
        'start_date' => now()->addDays(3)->toDateString(),
        ...$overrides,
    ];
}
