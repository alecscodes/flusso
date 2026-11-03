<?php

namespace App\Http\Requests;

use App\Models\Counterparty;
use App\Services\CounterpartyService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create and update share one rule: a person has a name no other person of the user has (ignoring case).
 */
class CounterpartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $counterparty = $this->route('counterparty');

        return $counterparty instanceof Counterparty
            ? $this->user()->can('update', $counterparty)
            : $this->user()->can('create', Counterparty::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                $existing = app(CounterpartyService::class)->findByName($this->user(), (string) $value);
                $editing = $this->route('counterparty');

                if ($existing !== null && ! ($editing instanceof Counterparty && $editing->is($existing))) {
                    $fail('You already have someone with this name.');
                }
            }],
        ];
    }
}
