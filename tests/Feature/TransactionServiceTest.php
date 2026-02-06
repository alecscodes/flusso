<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;

test('transaction service creates income transaction and updates balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
        'currency' => 'EUR',
    ]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'income',
    ]);

    $service = app(TransactionService::class);
    $transaction = $service->createTransaction($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'type' => TransactionType::Income,
        'amount' => 500.00,
        'currency' => 'EUR',
        'description' => 'Test income',
        'date' => now()->toDateString(),
    ]);

    expect($transaction)->toBeInstanceOf(Transaction::class)
        ->and($transaction->type)->toBe(TransactionType::Income)
        ->and($transaction->amount)->toBe('500.00')
        ->and($account->fresh()->balance)->toBe('1500.00');
});

test('transaction service creates expense transaction and updates balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
        'currency' => 'EUR',
    ]);
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    $service = app(TransactionService::class);
    $transaction = $service->createTransaction($user, [
        'account_id' => $account->id,
        'category_id' => $category->id,
        'type' => TransactionType::Expense,
        'amount' => 300.00,
        'currency' => 'EUR',
        'description' => 'Test expense',
        'date' => now()->toDateString(),
    ]);

    expect($transaction)->toBeInstanceOf(Transaction::class)
        ->and($transaction->type)->toBe(TransactionType::Expense)
        ->and($account->fresh()->balance)->toBe('700.00');
});

test('transaction service updates transaction and adjusts balances correctly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
        'currency' => 'EUR',
    ]);

    $service = app(TransactionService::class);
    $transaction = $service->createTransaction($user, [
        'account_id' => $account->id,
        'type' => TransactionType::Expense,
        'amount' => 200.00,
        'currency' => 'EUR',
        'date' => now()->toDateString(),
    ]);

    expect($account->fresh()->balance)->toBe('800.00');

    $service->updateTransaction($transaction, [
        'amount' => 300.00,
    ]);

    expect($account->fresh()->balance)->toBe('700.00');
});

test('transaction service deletes transaction and reverts balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
        'currency' => 'EUR',
    ]);

    $service = app(TransactionService::class);
    $transaction = $service->createTransaction($user, [
        'account_id' => $account->id,
        'type' => TransactionType::Expense,
        'amount' => 200.00,
        'currency' => 'EUR',
        'date' => now()->toDateString(),
    ]);

    expect($account->fresh()->balance)->toBe('800.00');

    $service->deleteTransaction($transaction);

    expect($account->fresh()->balance)->toBe('1000.00')
        ->and(Transaction::find($transaction->id))->toBeNull();
});

test('transaction service creates transfer between accounts', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
        'currency' => 'EUR',
    ]);
    $toAccount = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 500.00,
        'currency' => 'EUR',
    ]);

    $service = app(TransactionService::class);
    $result = $service->createTransfer($user, [
        'from_account_id' => $fromAccount->id,
        'to_account_id' => $toAccount->id,
        'amount' => 300.00,
        'description' => 'Test transfer',
        'date' => now()->toDateString(),
    ]);

    expect($result)->toHaveKeys(['outgoing', 'incoming'])
        ->and($result['outgoing']->type)->toBe(TransactionType::Transfer)
        ->and($result['incoming']->type)->toBe(TransactionType::Transfer)
        ->and($result['outgoing']->linked_transaction_id)->toBe($result['incoming']->id)
        ->and($result['incoming']->linked_transaction_id)->toBe($result['outgoing']->id)
        ->and($fromAccount->fresh()->balance)->toBe('700.00')
        ->and($toAccount->fresh()->balance)->toBe('800.00');
});

test('transaction service creates transfer with currency conversion', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
        'currency' => 'EUR',
    ]);
    $toAccount = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 500.00,
        'currency' => 'USD',
    ]);

    $service = app(TransactionService::class);
    $result = $service->createTransfer($user, [
        'from_account_id' => $fromAccount->id,
        'to_account_id' => $toAccount->id,
        'amount' => 100.00,
        'exchange_rate' => 1.10,
        'date' => now()->toDateString(),
    ]);

    expect($result['outgoing']->amount)->toBe('100.00')
        ->and($result['incoming']->amount)->toBe('110.00')
        ->and($fromAccount->fresh()->balance)->toBe('900.00')
        ->and($toAccount->fresh()->balance)->toBe('610.00');
});

test('transaction service deletes transfer and reverts both account balances', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
        'currency' => 'EUR',
    ]);
    $toAccount = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 500.00,
        'currency' => 'EUR',
    ]);

    $service = app(TransactionService::class);
    $result = $service->createTransfer($user, [
        'from_account_id' => $fromAccount->id,
        'to_account_id' => $toAccount->id,
        'amount' => 200.00,
        'date' => now()->toDateString(),
    ]);

    expect($fromAccount->fresh()->balance)->toBe('800.00')
        ->and($toAccount->fresh()->balance)->toBe('700.00');

    $service->deleteTransaction($result['outgoing']);

    expect($fromAccount->fresh()->balance)->toBe('1000.00')
        ->and($toAccount->fresh()->balance)->toBe('500.00')
        ->and(Transaction::find($result['outgoing']->id))->toBeNull()
        ->and(Transaction::find($result['incoming']->id))->toBeNull();
});

test('transaction service throws exception for transfer to same account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'balance' => 1000.00,
    ]);

    $service = app(TransactionService::class);

    expect(fn () => $service->createTransfer($user, [
        'from_account_id' => $account->id,
        'to_account_id' => $account->id,
        'amount' => 100.00,
    ]))->toThrow(\InvalidArgumentException::class);
});

test('transaction service calculates period summary correctly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 1000.00]);

    $service = app(TransactionService::class);

    $service->createTransaction($user, [
        'account_id' => $account->id,
        'type' => TransactionType::Income,
        'amount' => 500.00,
        'currency' => 'EUR',
        'date' => now()->toDateString(),
    ]);

    $service->createTransaction($user, [
        'account_id' => $account->id,
        'type' => TransactionType::Expense,
        'amount' => 200.00,
        'currency' => 'EUR',
        'date' => now()->toDateString(),
    ]);

    $summary = $service->getTransactionSummaryForPeriod(
        $user,
        now()->startOfMonth(),
        now()->endOfMonth()
    );

    expect($summary['income'])->toBe(500.0)
        ->and($summary['expenses'])->toBe(200.0)
        ->and($summary['net'])->toBe(300.0)
        ->and($summary['transaction_count'])->toBe(2);
});
