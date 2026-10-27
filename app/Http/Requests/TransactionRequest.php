<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Closure;
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
        return self::rulesFor($this->user(), $this->all(), $this->route('transaction'));
    }

    /**
     * Also used by the MCP tools, so both entry points validate identically.
     *
     * An edited transaction may keep its current accounts even if they were archived since.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function rulesFor(User $user, array $input, ?Transaction $transaction = null): array
    {
        $ownedCategory = $user->ownedCategoryRule($transaction?->category_id);

        // A category must be of the same kind as the transaction (income/expense).
        if (in_array($input['type'] ?? null, [TransactionType::Income->value, TransactionType::Expense->value], true)) {
            $ownedCategory->where('type', $input['type']);
        }

        return [
            'type' => ['required', Rule::enum(TransactionType::class)],
            'account_id' => ['required', 'integer', $user->ownedAccountRule($transaction?->account_id)],
            'category_id' => ['nullable', 'integer', 'prohibited_if:type,transfer', $ownedCategory],
            'to_account_id' => ['required_if:type,transfer', 'nullable', 'integer', 'different:account_id', 'prohibited_unless:type,transfer', $user->ownedAccountRule($transaction?->to_account_id)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'to_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2', 'prohibited_unless:type,transfer', fn (string $attribute, mixed $value, Closure $fail) => self::sameCurrencyReceivesWhatLeft($user, $input, $value, $fail)],
            'description' => ['nullable', 'string', 'max:1000'],
            'date' => ['required', 'date_format:Y-m-d'],
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

    /**
     * Between two accounts of one currency exactly what left arrives, so a different amount received is a mistake.
     *
     * @param  array<string, mixed>  $input
     */
    private static function sameCurrencyReceivesWhatLeft(User $user, array $input, mixed $received, Closure $fail): void
    {
        $currencies = $user->accounts()->withTrashed()
            ->whereIn('id', [(int) ($input['account_id'] ?? 0), (int) ($input['to_account_id'] ?? 0)])
            ->pluck('currency', 'id');

        $sameCurrency = $currencies->count() === 2 && $currencies->unique()->count() === 1;

        if ($sameCurrency && round((float) $received, 2) !== round((float) ($input['amount'] ?? 0), 2)) {
            $fail('Between accounts of the same currency the amount received equals the amount sent.');
        }
    }
}
