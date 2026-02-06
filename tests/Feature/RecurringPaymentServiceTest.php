<?php

use App\Enums\IntervalType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\User;
use App\Services\RecurringPaymentService;

test('recurring payment service creates recurring payment and generates initial payments', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'Netflix Subscription',
        'amount' => 15.99,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    expect($recurringPayment)->toBeInstanceOf(RecurringPayment::class)
        ->and($recurringPayment->name)->toBe('Netflix Subscription')
        ->and($recurringPayment->payments()->count())->toBeGreaterThan(0);
});

test('recurring payment service generates payments for 12 months ahead', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'Monthly Rent',
        'amount' => 1000.00,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $paymentsCount = $recurringPayment->payments()->count();
    expect($paymentsCount)->toBeGreaterThanOrEqual(12);
});

test('recurring payment service respects installments limit', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'Phone Payment Plan',
        'amount' => 50.00,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        'installments' => 6,
        'is_active' => true,
    ]);

    expect($recurringPayment->payments()->count())->toBe(6);
});

test('recurring payment service respects end date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'Gym Membership',
        'amount' => 30.00,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonths(3)->toDateString(),
        'is_active' => true,
    ]);

    expect($recurringPayment->payments()->count())->toBeLessThanOrEqual(4);
});

test('recurring payment service allows one-time planned payment when start and end date are the same', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);
    $oneTimeDate = now()->addDays(5)->toDateString();

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'One-time Fee',
        'amount' => 50.00,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => $oneTimeDate,
        'end_date' => $oneTimeDate,
        'is_active' => true,
    ]);

    expect($recurringPayment->start_date->toDateString())->toBe($oneTimeDate)
        ->and($recurringPayment->end_date->toDateString())->toBe($oneTimeDate)
        ->and($recurringPayment->payments()->count())->toBe(1)
        ->and($recurringPayment->payments()->first()->due_date->toDateString())->toBe($oneTimeDate);
});

test('recurring payment service deactivates and removes unpaid payments', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'Subscription',
        'amount' => 10.00,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $initialCount = $recurringPayment->payments()->count();
    expect($initialCount)->toBeGreaterThan(0);

    $service->deactivate($recurringPayment);

    expect($recurringPayment->fresh()->is_active)->toBeFalse()
        ->and($recurringPayment->payments()->count())->toBe(0);
});

test('recurring payment service updates future payments when recurring payment is updated', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'Old Name',
        'amount' => 10.00,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $service->updateRecurringPayment($recurringPayment, [
        'name' => 'New Name',
        'amount' => 20.00,
    ]);

    $recurringPayment->refresh();
    expect($recurringPayment->name)->toBe('New Name')
        ->and($recurringPayment->amount)->toBe('20.00');

    $unpaidPayment = $recurringPayment->payments()->where('is_paid', false)->first();
    expect($unpaidPayment->amount)->toBe('20.00')
        ->and($unpaidPayment->description)->toBe('New Name');
});

test('recurring payment service deletes recurring payment and unpaid payments', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'To Delete',
        'amount' => 10.00,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $recurringPaymentId = $recurringPayment->id;
    $service->deleteRecurringPayment($recurringPayment);

    expect(RecurringPayment::find($recurringPaymentId))->toBeNull()
        ->and(Payment::where('recurring_payment_id', $recurringPaymentId)->count())->toBe(0);
});

test('recurring payment service provides statistics', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $service = app(RecurringPaymentService::class);
    $recurringPayment = $service->createRecurringPayment($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'name' => 'Stats Test',
        'amount' => 100.00,
        'currency' => 'EUR',
        'interval_type' => IntervalType::Months,
        'interval_value' => 1,
        'start_date' => now()->toDateString(),
        'installments' => 5,
        'is_active' => true,
    ]);

    $stats = $service->getStatistics($recurringPayment);

    expect($stats)->toHaveKeys(['total_payments', 'paid_payments', 'unpaid_payments', 'total_paid_amount', 'total_pending_amount', 'next_due_date'])
        ->and($stats['total_payments'])->toBe(5)
        ->and($stats['paid_payments'])->toBe(0)
        ->and($stats['unpaid_payments'])->toBe(5)
        ->and($stats['total_pending_amount'])->toBe(500.0);
});
