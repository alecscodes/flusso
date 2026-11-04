<?php

namespace App\Services\Statements;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Text of a PDF through poppler's `pdftotext -layout` (installed in the Docker
 * image). A thin class so tests can swap it: they never run the binary.
 */
class PdfTextExtractor
{
    /**
     * Null when the tool is missing or fails; a scanned PDF simply gives no text.
     */
    public function extract(string $path): ?string
    {
        try {
            $result = Process::timeout(60)->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? $result->output() : null;
    }
}
