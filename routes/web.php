<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CounterpartyController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\LiquidityAlertController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RecurringPaymentController;
use App\Http\Controllers\RecurringSuggestionController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\StatementImportController;
use App\Http\Controllers\TransactionCleanupController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionSuggestionController;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()) && Setting::isRegistrationAllowed(),
    ]);
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::resource('accounts', AccountController::class)->except(['create', 'edit']);
    Route::resource('categories', CategoryController::class)->except(['create', 'edit']);
    Route::get('transactions/shortcuts', [TransactionSuggestionController::class, 'shortcuts'])->name('transactions.shortcuts');
    Route::get('transactions/suggest-category', [TransactionSuggestionController::class, 'category'])->middleware('throttle:60,1')->name('transactions.suggestCategory');
    Route::post('transactions/parse-text', [TransactionSuggestionController::class, 'parseText'])->middleware('throttle:30,1')->name('transactions.parse-text');
    Route::controller(TransactionCleanupController::class)->prefix('transactions/cleanup')->name('cleanup.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('apply-all', 'applyAll')->name('applyAll');
        Route::patch('{transaction}', 'apply')->name('apply');
    });
    Route::resource('transactions', TransactionController::class)->except(['create', 'edit', 'show']);
    Route::get('transactions/{transaction}/files/{file}/download', [TransactionController::class, 'downloadFile'])->name('transactions.files.download');
    Route::post('receipts/scan', [ReceiptController::class, 'scan'])->middleware('throttle:20,1')->name('receipts.scan');
    Route::post('receipts', [ReceiptController::class, 'store'])->name('receipts.store');
    Route::controller(RecurringSuggestionController::class)->prefix('planned-payments/suggestions/dismissals')->middleware('throttle:60,1')->name('planned-payments.suggestions.')->group(function () {
        Route::post('/', 'dismiss')->name('dismiss');
        Route::delete('{key}', 'restore')->name('restore');
    });
    Route::resource('planned-payments', RecurringPaymentController::class)->except(['create', 'edit'])->parameters(['planned-payments' => 'recurring_payment']);
    Route::controller(PaymentController::class)->prefix('payments')->name('payments.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::patch('{payment}', 'update')->name('update');
        Route::delete('{payment}', 'destroy')->name('destroy');
        Route::patch('{payment}/skip', 'skip')->name('skip');
        Route::patch('{payment}/unskip', 'unskip')->name('unskip');
        Route::patch('{payment}/mark-paid', 'markPaid')->name('mark-paid');
        Route::patch('{payment}/mark-unpaid', 'markUnpaid')->name('mark-unpaid');
    });
    Route::post('liquidity-alerts/read', [LiquidityAlertController::class, 'markRead'])->name('liquidity-alerts.read');
    Route::get('counterparties/options', [CounterpartyController::class, 'options'])->name('counterparties.options');
    Route::resource('counterparties', CounterpartyController::class)->except(['create', 'edit']);
    Route::post('counterparties/{counterparty}/assign', [CounterpartyController::class, 'assign'])->name('counterparties.assign');
    Route::post('settlements', [SettlementController::class, 'store'])->name('settlements.store');
    Route::delete('settlements/{settlement}', [SettlementController::class, 'destroy'])->name('settlements.destroy');
    Route::controller(StatementController::class)->prefix('statements')->name('statements.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('{statement}', 'show')->name('show');
        Route::patch('{statement}', 'update')->name('update');
        Route::delete('{statement}', 'destroy')->name('destroy');
        Route::get('{statement}/download', 'download')->name('download');
        Route::post('{statement}/reparse', 'reparse')->name('reparse');
    });
    Route::controller(StatementImportController::class)->prefix('statements')->name('statements.')->group(function () {
        Route::post('{statement}/import-lines', 'store')->name('import-lines');
        Route::post('{statement}/lines/{line}/ignore', 'ignore')->name('ignore-line');
        Route::post('{statement}/lines/{line}/restore', 'restore')->name('restore-line');
        Route::post('{statement}/lines/{line}/flip-sign', 'flipSign')->name('flip-line-sign');
        Route::post('{statement}/lines/{line}/confirm-sign', 'confirmSign')->name('confirm-line-sign');
    });
    Route::get('insights', [InsightsController::class, 'index'])->name('insights.index');
    Route::get('currency/rates', [CurrencyController::class, 'getRates'])->name('currency.rates');
});

require __DIR__.'/settings.php';
