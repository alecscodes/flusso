<?php

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

Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('accounts', \App\Http\Controllers\AccountController::class)->except(['create', 'edit']);
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)->except(['create', 'edit']);
    Route::resource('transactions', \App\Http\Controllers\TransactionController::class)->except(['create', 'edit']);
    Route::post('transactions/transfer', [\App\Http\Controllers\TransactionController::class, 'transfer'])->name('transactions.transfer');
    Route::resource('recurring-payments', \App\Http\Controllers\RecurringPaymentController::class)->except(['create', 'edit']);
    Route::post('recurring-payments/{recurringPayment}/generate-payments', [\App\Http\Controllers\RecurringPaymentController::class, 'generatePayments'])->name('recurring-payments.generate-payments');
    Route::get('payments', [\App\Http\Controllers\PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments', [\App\Http\Controllers\PaymentController::class, 'store'])->name('payments.store');
    Route::patch('payments/{payment}/mark-paid', [\App\Http\Controllers\PaymentController::class, 'markPaid'])->name('payments.mark-paid');
    Route::patch('payments/{payment}/mark-unpaid', [\App\Http\Controllers\PaymentController::class, 'markUnpaid'])->name('payments.mark-unpaid');
    Route::get('currency/rates', [\App\Http\Controllers\CurrencyController::class, 'getRates'])->name('currency.rates');
});

require __DIR__.'/settings.php';
