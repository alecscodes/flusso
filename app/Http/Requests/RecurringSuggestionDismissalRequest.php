<?php

namespace App\Http\Requests;

use App\Enums\SuggestionDismissalKind;
use App\Models\RecurringPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dismissing (POST, with a kind) and taking back (DELETE, the key is in the URL) a suggestion.
 */
class RecurringSuggestionDismissalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', RecurringPayment::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('key') !== null) {
            $this->merge(['key' => $this->route('key')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dismissing = $this->isMethod('POST');

        return [
            'key' => ['required', 'string', 'max:191', 'regex:/^[a-z0-9]+( [a-z0-9]+)*$/'],
            'kind' => [$dismissing ? 'required' : 'prohibited', Rule::enum(SuggestionDismissalKind::class)],
            'snooze_until' => ['nullable', 'date_format:Y-m-d', 'after:today', 'before_or_equal:'.now()->addYears(5)->toDateString()],
        ];
    }
}
