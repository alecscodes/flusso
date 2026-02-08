<?php

use App\Enums\IntervalType;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payment;
use App\Models\RecurringPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;

test('payment service can mark payment as paid and create transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
    ]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    $payment = Payment::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'type' => PaymentType::Expense,
        'amount' => 100.00,
        'is_paid' => false,
    ]);

    $service = app(PaymentService::class);
    $transaction = $service->markAsPaid($payment);

    expect($payment->fresh()->is_paid)->toBeTrue()
        ->and($payment->fresh()->transaction_id)->toBe($transaction->id)
        ->and($account->fresh()->balance)->toBe('900.00')
        ->and(Transaction::find($transaction->id))->not->toBeNull();
});

test('payment service can mark payment as unpaid and revert transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
    ]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    $payment = Payment::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'type' => PaymentType::Expense,
        'amount' => 100.00,
        'is_paid' => true,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'type' => 'expense',
        'amount' => 100.00,
    ]);

    $payment->update(['transaction_id' => $transaction->id]);
    $account->decrement('balance', 100.00);

    expect($account->fresh()->balance)->toBe('900.00');

    $service = app(PaymentService::class);
    $service->markAsUnpaid($payment, deleteTransaction: true);

    expect($payment->fresh()->is_paid)->toBeFalse()
        ->and($payment->fresh()->transaction_id)->toBeNull()
        ->and($account->fresh()->balance)->toBe('1000.00')
        ->and(Transaction::find($transaction->id))->toBeNull();
});

test('payment service generates payments from recurring payment', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => 'expense']);

    $recurringPayment = RecurringPayment::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'interval_type' => IntervalType::Days,
        'interval_value' => 7,
        'start_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $service = app(PaymentService::class);
    $payments = $service->generatePaymentsForRecurringPayment($recurringPayment, now()->addDays(30));

    expect($payments)->not->toBeEmpty()
        ->and($payments->count())->toBeGreaterThan(0)
        ->and(Payment::where('recurring_payment_id', $recurringPayment->id)->count())->toBe($payments->count());
});
