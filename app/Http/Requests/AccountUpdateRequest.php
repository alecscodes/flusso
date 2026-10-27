<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Models\Transaction;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AccountUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::rulesFor($this->route('account'));
    }

    /**
     * Also used by the MCP tools.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function rulesFor(Account $account): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3', 'uppercase', fn (string $attribute, mixed $value, Closure $fail) => self::currencyIsLocked($account, $value, $fail)],
            'balance' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'is_savings' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Transactions are always in their account's currency, so once an account has
     * any (even deleted ones, which can be restored) its currency is fixed.
     */
    private static function currencyIsLocked(Account $account, mixed $value, Closure $fail): void
    {
        if ($value !== $account->currency && Transaction::withTrashed()->forAccount($account->id)->exists()) {
            $fail('The currency cannot be changed once the account has transactions.');
        }
    }
}
