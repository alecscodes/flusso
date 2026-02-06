<?php

use App\Enums\IntervalType;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringPayment;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('user can create a one-time planned recurring payment when start and end date are the same', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $oneTimeDate = now()->addDays(10)->toDateString();

    $response = actingAs($user)
        ->post(route('recurring-payments.store'), [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'name' => 'One-time planned payment',
            'amount' => 75.00,
            'currency' => 'eur',
            'interval_type' => IntervalType::Months->value,
            'interval_value' => 1,
            'start_date' => $oneTimeDate,
            'end_date' => $oneTimeDate,
            'is_active' => true,
        ]);

    $response->assertRedirect(route('recurring-payments.index'));

    $recurringPayment = RecurringPayment::where('user_id', $user->id)->where('name', 'One-time planned payment')->first();
    expect($recurringPayment)->not->toBeNull()
        ->and($recurringPayment->start_date->toDateString())->toBe($oneTimeDate)
        ->and($recurringPayment->end_date->toDateString())->toBe($oneTimeDate)
        ->and($recurringPayment->payments()->count())->toBe(1);
});

test('user can update a recurring payment to one-time planned when start and end date are the same', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $recurringPayment = RecurringPayment::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'start_date' => now()->addDays(5),
        'end_date' => now()->addMonths(2),
    ]);
    $oneTimeDate = now()->addDays(15)->toDateString();

    $response = actingAs($user)
        ->put(route('recurring-payments.update', $recurringPayment), [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'name' => $recurringPayment->name,
            'amount' => $recurringPayment->amount,
            'currency' => strtolower($recurringPayment->currency),
            'interval_type' => $recurringPayment->interval_type->value,
            'interval_value' => $recurringPayment->interval_value,
            'start_date' => $oneTimeDate,
            'end_date' => $oneTimeDate,
            'is_active' => true,
        ]);

    $response->assertRedirect(route('recurring-payments.index'));

    $recurringPayment->refresh();
    expect($recurringPayment->start_date->toDateString())->toBe($oneTimeDate)
        ->and($recurringPayment->end_date->toDateString())->toBe($oneTimeDate);
});
