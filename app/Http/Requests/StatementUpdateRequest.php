<?php

namespace App\Http\Requests;

use App\Models\Statement;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit what the owner knows about a statement: the institution, the account (an
 * app account or just a label) and the period it covers.
 */
class StatementUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('statement'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Statement $statement */
        $statement = $this->route('statement');

        // The statement may keep its account even if it was archived since.
        return self::detailRules($this->user(), $statement->account_id);
    }

    /**
     * Shared with the upload form, which sets the same details for every file it carries.
     *
     * @return array<string, mixed>
     */
    public static function detailRules(User $user, ?int $keepAccountId = null): array
    {
        return [
            'institution' => ['nullable', 'string', 'max:120'],
            'account_label' => ['nullable', 'string', 'max:120'],
            'account_id' => ['nullable', 'integer', $user->ownedAccountRule($keepAccountId)],
            'period_start' => ['nullable', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ];
    }
}
