<?php

use App\Http\Controllers\Settings\BannedIpsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RegistrationController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('Settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/registration', [RegistrationController::class, 'edit'])->name('registration.edit');
    Route::patch('settings/registration', [RegistrationController::class, 'update'])->name('registration.update');

    Route::get('settings/banned-ips', [BannedIpsController::class, 'index'])->name('banned-ips.index');
    Route::delete('settings/banned-ips/unban', [BannedIpsController::class, 'destroy'])->name('banned-ips.destroy');

    Route::get('settings/finance', [\App\Http\Controllers\Settings\FinanceController::class, 'edit'])
        ->name('settings.finance.edit');
    Route::patch('settings/finance', [\App\Http\Controllers\Settings\FinanceController::class, 'update'])
        ->name('settings.finance.update');
});
