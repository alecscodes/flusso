<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Rules\BelongsToUser;
use Illuminate\Foundation\Http\FormRequest;

class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => [
                'required',
                'integer',
                'exists:accounts,id',
                new BelongsToUser(Account::class),
            ],
            'to_account_id' => [
                'required',
                'integer',
                'exists:accounts,id',
                'different:from_account_id',
                new BelongsToUser(Account::class),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'description' => ['nullable', 'string', 'max:1000'],
            'date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_account_id.different' => 'Cannot transfer to the same account.',
        ];
    }
}
