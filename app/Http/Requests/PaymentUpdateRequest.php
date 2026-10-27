<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

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
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'integer', $user->ownedAccountRule()],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
