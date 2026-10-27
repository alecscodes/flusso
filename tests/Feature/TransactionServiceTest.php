<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionFile;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Storage;

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
        'account_id' => $account->id,
        'type' => TransactionType::Expense,
        'amount' => 300.00,
        'date' => now()->toDateString(),
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

test('transaction service calculates period summary correctly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'balance' => 1000.00]);

    $transactionService = app(TransactionService::class);
    $currencyService = app(CurrencyService::class);

    $incomeTransaction = $transactionService->createTransaction($user, [
        'account_id' => $account->id,
        'type' => TransactionType::Income,
        'amount' => 500.00,
        'currency' => 'EUR',
        'date' => now()->toDateString(),
    ]);

    $expenseTransaction = $transactionService->createTransaction($user, [
        'account_id' => $account->id,
        'type' => TransactionType::Expense,
        'amount' => 200.00,
        'currency' => 'EUR',
        'date' => now()->toDateString(),
    ]);

    $summary = $transactionService->getTransactionSummaryForPeriod(
        $user,
        now()->startOfMonth(),
        now()->endOfMonth()
    );

    // Test currency conversion to primary currency
    $primaryCurrency = $currencyService->getPrimaryCurrency($user);
    $expectedIncome = $primaryCurrency === 'EUR' ? 500.0 :
        $currencyService->convert(500.0, 'EUR', $primaryCurrency) ?? 500.0;
    $expectedExpenses = $primaryCurrency === 'EUR' ? 200.0 :
        $currencyService->convert(200.0, 'EUR', $primaryCurrency) ?? 200.0;
    $expectedNet = $expectedIncome - $expectedExpenses;

    expect(abs($summary['income'] - $expectedIncome))->toBeLessThan(0.01)
        ->and(abs($summary['expenses'] - $expectedExpenses))->toBeLessThan(0.01)
        ->and(abs($summary['net'] - $expectedNet))->toBeLessThan(0.01)
        ->and($summary['transaction_count'])->toBe(2);
});

test('attached files are removed only when a transaction is deleted for good', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    $transaction = app(TransactionService::class)->createTransaction($user, ['type' => 'expense', 'account_id' => $account->id, 'amount' => 5]);
    Storage::disk('local')->put('receipts/a.pdf', 'x');
    TransactionFile::create(['transaction_id' => $transaction->id, 'filename' => 'a.pdf', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf', 'size' => 1, 'path' => 'receipts/a.pdf']);

    app(TransactionService::class)->deleteTransaction($transaction);
    expect(Storage::disk('local')->exists('receipts/a.pdf'))->toBeTrue()
        ->and(TransactionFile::count())->toBe(1);

    $transaction->restore();
    $transaction->forceDelete();
    expect(Storage::disk('local')->exists('receipts/a.pdf'))->toBeFalse()
        ->and(TransactionFile::count())->toBe(0);
});
