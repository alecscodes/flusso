<?php

namespace App\Http\Requests;

use App\Models\Statement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The lines to import from one statement, with the description and category chosen
 * for each, and the account they go into: the statement's own when it has one,
 * otherwise the one the owner picks.
 */
class StatementImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('statement'));
    }

    protected function prepareForValidation(): void
    {
        /** @var Statement $statement */
        $statement = $this->route('statement');

        if ($statement->account_id !== null) {
            $this->merge(['account_id' => $statement->account_id]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Statement $statement */
        $statement = $this->route('statement');
        $user = $this->user();

        return [
            'account_id' => ['required', 'integer', $user->ownedAccountRule()],
            'import_all' => ['nullable', 'boolean'],
            'lines' => [Rule::requiredIf(! $this->boolean('import_all')), 'array', 'max:1000'],
            'lines.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('statement_lines', 'id')->where('statement_id', $statement->id)->where('user_id', $user->id),
            ],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.category_id' => ['nullable', 'integer', $user->ownedCategoryRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.required' => 'Choose the account to import into.',
            'account_id.exists' => $this->route('statement')->account_id !== null
                ? 'The statement\'s account is archived, so nothing can be imported into it.'
                : 'Choose one of your accounts.',
            'lines.required' => 'Select at least one line.',
            'lines.*.id.exists' => 'That line is not part of this statement.',
            'lines.*.category_id.exists' => 'Choose one of your categories.',
        ];
    }

    /**
     * The owner's choices keyed by line id.
     *
     * @return array<int, array{description: string|null, category_id: int|null}>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ((array) $this->validated('lines', []) as $line) {
            $choices[(int) $line['id']] = [
                'description' => $line['description'] ?? null,
                'category_id' => isset($line['category_id']) ? (int) $line['category_id'] : null,
            ];
        }

        return $choices;
    }
}
