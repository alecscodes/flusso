<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Everything is optional: by default the occurrence is paid as planned, today.
 */
class MarkPaymentPaidRequest extends FormRequest
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
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'date' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer', $user->ownedAccountRule()],
        ];
    }
}
