<?php

namespace App\Http\Requests\Settings;

use App\Enums\AiFeature;
use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiSettingsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'driver' => ['required', Rule::in(AiSettings::DRIVERS)],
            'url' => ['nullable', 'url:http,https', 'max:255', Rule::requiredIf(fn () => $this->input('driver') === 'openai-compatible')],
            'model' => ['nullable', 'string', 'max:120', Rule::requiredIf(fn () => $this->boolean('enabled'))],
            'api_key' => ['nullable', 'string', 'max:1000'],
            'clear_api_key' => ['nullable', 'boolean'],
            'vision' => ['required', 'boolean'],
            'features' => ['required', 'array'],
            ...collect(AiFeature::cases())->mapWithKeys(fn (AiFeature $feature) => [
                "features.{$feature->value}" => ['required', 'boolean'],
            ])->all(),
        ];
    }
}
