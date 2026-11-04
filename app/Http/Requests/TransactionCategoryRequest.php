<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sets the category of one transaction: the category must be the user's own and of the transaction's kind
 * (a transfer matches none, so it cannot get one).
 */
class TransactionCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('transaction'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->route('transaction');

        return [
            'category_id' => ['required', 'integer', $this->user()->ownedCategoryRule()->where('type', $transaction->type->value)],
        ];
    }
}
