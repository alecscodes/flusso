<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Rules\BelongsToUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('transaction'));
    }

    public function rules(): array
    {
        return [
            'account_id' => [
                'required',
                'integer',
                'exists:accounts,id',
                new BelongsToUser(Account::class),
            ],
            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
                new BelongsToUser(Category::class),
            ],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
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
        ];
    }
}
