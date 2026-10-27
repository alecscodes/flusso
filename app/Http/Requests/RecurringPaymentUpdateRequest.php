<?php

namespace App\Http\Requests;

use App\Enums\IntervalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringPaymentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('recurring_payment'));
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at')],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'interval_type' => ['required', Rule::enum(IntervalType::class)],
            'interval_value' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'installments' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper($this->currency),
            ]);
        }
    }
}
