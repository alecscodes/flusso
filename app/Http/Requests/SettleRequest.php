<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An income and the expenses it settles, with the amount for each. Who owns what is checked by the SettlementService.
 */
class SettleRequest extends FormRequest
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
            'income_id' => ['required', 'integer'],
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.expense_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }
}
