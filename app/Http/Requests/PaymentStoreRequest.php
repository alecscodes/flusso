<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at')],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:1000'],
            'due_date' => ['required', 'date'],
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
