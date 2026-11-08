<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InsightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Any day of the period to show (today when absent), in the app timezone.
     */
    public function anchor(): ?CarbonImmutable
    {
        $date = $this->validated('date');

        return is_string($date) ? CarbonImmutable::parse($date)->startOfDay() : null;
    }
}
