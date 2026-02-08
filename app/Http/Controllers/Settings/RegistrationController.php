<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RegistrationUpdateRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    /**
     * Display the registration settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('Settings/Registration', [
            'registration_enabled' => filter_var(
                Setting::get('registration_enabled', false),
                FILTER_VALIDATE_BOOLEAN
            ),
        ]);
    }

    /**
     * Update the registration settings.
     */
    public function update(RegistrationUpdateRequest $request): RedirectResponse
    {
        Setting::set('registration_enabled', $request->boolean('registration_enabled') ? '1' : '0');

        return redirect()->route('registration.edit')->with('success', 'Registration settings updated successfully.');
    }
}
