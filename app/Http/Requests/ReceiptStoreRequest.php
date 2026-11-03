<?php

namespace App\Http\Requests;

use App\Enums\CategoryType;
use App\Models\Transaction;
use App\Services\Receipts\ReceiptFileStore;
use App\Services\Receipts\ReceiptScanner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What the owner confirmed after reviewing a scanned receipt: expenses on one
 * account, in that account's currency, with the amounts exactly as typed.
 */
class ReceiptStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Transaction::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'account_id' => ['required', 'integer', $user->ownedAccountRule()],
            'date' => ['required', 'date_format:Y-m-d'],
            'token' => ['required', 'string', 'regex:'.ReceiptFileStore::TOKEN_PATTERN],
            'transactions' => ['required', 'array', 'min:1', 'max:'.ReceiptScanner::MAX_LINES],
            'transactions.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'transactions.*.category_id' => ['nullable', 'integer', $user->ownedCategoryRule()->where('type', CategoryType::Expense->value)],
            'transactions.*.description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'transactions.*.amount.min' => 'Amount must be at least 0.01.',
            'transactions.*.amount.decimal' => 'Use at most two decimals.',
        ];
    }
}
