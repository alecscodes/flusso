<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('payment'));
    }

    public function rules(): array
    {
        return self::rulesFor($this->user());
    }

    /**
     * Also used by the MCP tools.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(User $user): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['required', 'date'],
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
