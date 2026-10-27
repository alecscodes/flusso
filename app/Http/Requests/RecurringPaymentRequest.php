<?php

namespace App\Http\Requests;

use App\Enums\IntervalType;
use App\Models\RecurringPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update share one set of rules. A planned payment ends never, on a
 * date, or after a number of installments (once = 1); currency is derived.
 */
class RecurringPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('recurring_payment');

        return $rule
            ? $this->user()->can('update', $rule)
            : $this->user()->can('create', RecurringPayment::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at')],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'interval_type' => ['required', Rule::enum(IntervalType::class)],
            'interval_value' => ['required', 'integer', 'min:1', 'max:1000'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', 'prohibits:installments'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:600'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.prohibits' => 'Choose either an end date or a number of payments, not both.',
        ];
    }
}
