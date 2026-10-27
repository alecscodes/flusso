<?php

namespace App\Http\Requests;

use App\Models\Payment;
use App\Models\User;
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::rulesFor($this->user(), $this->route('payment'), $this->all());
    }

    /**
     * Also used by the MCP tools. Paying from an account in another currency than
     * the planned one needs the amount (the planned amount is in the wrong currency).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rulesFor(User $user, Payment $payment, array $input = []): array
    {
        $accountId = $input['account_id'] ?? null;
        $needsAmount = filled($accountId) && $user->accounts()->whereKey($accountId)->value('currency') !== $payment->currency;

        return [
            'amount' => [Rule::requiredIf($needsAmount), 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'integer', $user->ownedAccountRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the amount in the currency of the account you pay from.',
        ];
    }
}
