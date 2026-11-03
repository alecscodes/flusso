<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AiFeature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AiSettingsUpdateRequest;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiSettings;
use App\Support\FlashToast;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

use function Laravel\Ai\agent;

class AiController extends Controller
{
    /**
     * Show the AI settings. The API key is never sent to the browser, only whether one is stored.
     */
    public function edit(AiSettings $settings): Response
    {
        $config = $settings->all();

        return Inertia::render('Settings/Ai', [
            'config' => [
                'enabled' => $config['enabled'],
                'driver' => $config['driver'],
                'url' => $config['url'],
                'model' => $config['model'],
                'has_api_key' => $config['api_key'] !== null,
                'vision' => $config['vision'],
                'features' => $config['features'],
            ],
            'drivers' => AiSettings::DRIVERS,
            'features' => array_map(fn (AiFeature $feature) => $feature->value, AiFeature::cases()),
        ]);
    }

    public function update(AiSettingsUpdateRequest $request, AiSettings $settings): RedirectResponse
    {
        $settings->save($request->validated());

        FlashToast::success('AI settings saved.');

        return redirect()->route('settings.ai.edit');
    }

    /**
     * Send a tiny prompt with the saved settings to see whether the model answers.
     */
    public function test(AiManager $ai): RedirectResponse
    {
        if (! $ai->isEnabled()) {
            FlashToast::error('Save a complete configuration with AI enabled first.');

            return redirect()->route('settings.ai.edit');
        }

        $response = $ai->prompt(agent(instructions: 'Reply with the single word OK.'), 'Ping', timeout: 30);

        if ($response === null) {
            FlashToast::error('The model did not answer. Check the URL, the key and the model name.');
        } else {
            FlashToast::success('The model answered.');
        }

        return redirect()->route('settings.ai.edit');
    }
}
