<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RecurringPaymentController;
use App\Http\Controllers\TransactionController;
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
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('accounts', AccountController::class)->except(['create', 'edit']);
    Route::resource('categories', CategoryController::class)->except(['create', 'edit']);
    Route::resource('transactions', TransactionController::class)->except(['create', 'edit', 'show']);
    Route::post('transactions/transfer', [TransactionController::class, 'transfer'])->name('transactions.transfer');
    Route::get('transactions/{transaction}/files/{file}/download', [TransactionController::class, 'downloadFile'])->name('transactions.files.download');
    Route::resource('recurring-payments', RecurringPaymentController::class)->except(['create', 'edit']);
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::patch('payments/{payment}/mark-paid', [PaymentController::class, 'markPaid'])->name('payments.mark-paid');
    Route::patch('payments/{payment}/mark-unpaid', [PaymentController::class, 'markUnpaid'])->name('payments.mark-unpaid');
    Route::get('currency/rates', [CurrencyController::class, 'getRates'])->name('currency.rates');
});

require __DIR__.'/settings.php';
