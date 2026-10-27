<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    /**
     * Show the finance settings page
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $currentPeriod = $user->getFinancialPeriod();

        return Inertia::render('Settings/Finance', [
            'resetDate' => $user->reset_date,
            'currentPeriod' => [
                'start' => $currentPeriod['start']->toDateString(),
                'end' => $currentPeriod['end']->toDateString(),
            ],
        ]);
    }

    /**
     * Update the finance settings
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reset_date' => ['nullable', 'integer', 'min:1', 'max:31'],
        ]);

        $request->user()->update([
            'reset_date' => $validated['reset_date'] ?? null,
        ]);

        FlashToast::success('Finance settings updated successfully.');

        return redirect()->route('settings.finance.edit');
    }
}
