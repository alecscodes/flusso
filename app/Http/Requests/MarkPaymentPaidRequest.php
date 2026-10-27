<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything is optional: by default the occurrence is paid as planned, today.
 */
class MarkPaymentPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('payment'));
    }

    public function rules(): array
    {
        return [
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at')],
        ];
    }
}
