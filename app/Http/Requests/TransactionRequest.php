<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update share one set of rules: a transaction is an expense, an
 * income or a transfer between two of the user's own accounts.
 */
class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $transaction
            ? $this->user()->can('update', $transaction)
            : $this->user()->can('create', Transaction::class);
    }

    public function rules(): array
    {
        $ownedAccount = Rule::exists('accounts', 'id')
            ->where('user_id', $this->user()->id)
            ->whereNull('deleted_at');

        $ownedCategory = Rule::exists('categories', 'id')
            ->where('user_id', $this->user()->id)
            ->whereNull('deleted_at');

        // A category must be of the same kind as the transaction (income/expense).
        if (in_array($this->input('type'), [TransactionType::Income->value, TransactionType::Expense->value], true)) {
            $ownedCategory->where('type', $this->input('type'));
        }

        return [
            'type' => ['required', Rule::enum(TransactionType::class)],
            'account_id' => ['required', 'integer', $ownedAccount],
            'category_id' => ['nullable', 'integer', 'prohibited_if:type,transfer', $ownedCategory],
            'to_account_id' => ['required_if:type,transfer', 'nullable', 'integer', 'different:account_id', 'prohibited_unless:type,transfer', $ownedAccount],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'to_amount' => ['nullable', 'numeric', 'min:0.01', 'prohibited_unless:type,transfer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'date' => ['required', 'date'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => [
                'file',
                'max:10240', // 10MB max per file
                'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.max' => 'Each file must not exceed 10MB.',
            'files.*.mimes' => 'Only PDF, Word, Excel, and image files are allowed.',
            'files.max' => 'You may upload a maximum of 5 files.',
            'amount.min' => 'Amount must be at least 0.01.',
            'date.required' => 'Date is required.',
            'account_id.required' => 'Account is required.',
            'to_account_id.different' => 'Cannot transfer to the same account.',
            'to_account_id.required_if' => 'Choose the account the money goes to.',
        ];
    }
}
