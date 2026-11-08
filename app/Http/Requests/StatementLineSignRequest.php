<?php

namespace App\Http\Requests;

use App\Models\Statement;
use App\Models\StatementLine;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Flip the sign of one line of a statement, for a PDF amount read with the wrong
 * sign. Nothing is sent: the line is in the URL, and must be the owner's and
 * belong to the statement of the URL.
 */
class StatementLineSignRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Statement $statement */
        $statement = $this->route('statement');
        /** @var StatementLine $line */
        $line = $this->route('line');

        abort_unless($line->statement_id === $statement->id, 404);

        return $this->user()->can('update', $statement) && $this->user()->can('update', $line);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
