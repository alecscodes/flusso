<?php

namespace App\Http\Requests;

use App\Models\Statement;
use App\Services\Statements\StatementService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * One or more statement files (PDF or CSV only, 20 MB each), optionally with the
 * institution, account and period they all belong to.
 */
class StatementUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Statement::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => [
                'required',
                'file',
                'max:'.StatementService::MAX_SIZE_KB,
                'extensions:pdf,csv',
                'mimetypes:application/pdf,text/csv,text/plain,application/csv,application/x-csv,text/x-csv,application/vnd.ms-excel',
                fn (string $attribute, mixed $value, Closure $fail) => self::checkContent($value, $fail),
            ],
            ...StatementUpdateRequest::detailRules($this->user()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.*.extensions' => 'Only PDF and CSV statements can be uploaded.',
            'files.*.mimetypes' => 'Only PDF and CSV statements can be uploaded.',
            'files.*.max' => 'A statement can be at most 20 MB.',
        ];
    }

    /**
     * A PDF must start like one and a CSV must be text, whatever the name says.
     */
    private static function checkContent(mixed $file, Closure $fail): void
    {
        if (! $file instanceof UploadedFile || $file->getRealPath() === false) {
            return;
        }

        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 1024);

        if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
            if (! str_starts_with(ltrim($head), '%PDF')) {
                $fail('This file is not a valid PDF.');
            }

            return;
        }

        if (str_contains($head, "\0")) {
            $fail('This file is not a text CSV.');
        }
    }
}
