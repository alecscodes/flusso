<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A change of sign for all the uncertain lines of a statement at once. Nothing is
 * sent: the statement is in the URL and must be the owner's.
 */
class StatementSignsRequest extends FormRequest
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
        return [];
    }
}
