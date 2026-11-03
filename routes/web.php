<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LiquidityAlertController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RecurringPaymentController;
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
    Route::controller(TransactionCleanupController::class)->prefix('transactions/cleanup')->name('cleanup.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('apply-all', 'applyAll')->name('applyAll');
        Route::patch('{transaction}', 'apply')->name('apply');
    });
    Route::resource('transactions', TransactionController::class)->except(['create', 'edit', 'show']);
    Route::get('transactions/{transaction}/files/{file}/download', [TransactionController::class, 'downloadFile'])->name('transactions.files.download');
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
    Route::get('currency/rates', [CurrencyController::class, 'getRates'])->name('currency.rates');
});

require __DIR__.'/settings.php';
