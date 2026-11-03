<?php

namespace App\Services\Receipts;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Text from a receipt photo with tesseract (installed in the Docker image), for
 * models that cannot look at images. Resolved from the container so tests can replace it.
 */
class ReceiptOcr
{
    public function read(string $imagePath): ?string
    {
        try {
            $process = new Process(['tesseract', $imagePath, 'stdout', '-l', 'eng+ita+ron']);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $text = trim($process->getOutput());

            return $text === '' ? null : $text;
        } catch (Throwable) {
            return null;
        }
    }
}
