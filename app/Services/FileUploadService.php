<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class FileUploadService
{
    public function handleTransactionFiles(Transaction $transaction, array $files): Collection
    {
        $uploadedFiles = collect();

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('transaction-files', 'local');

            if (! $path) {
                continue;
            }

            $uploadedFiles->push($transaction->files()->create([
                'filename' => basename($path),
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $path,
            ]));
        }

        return $uploadedFiles;
    }
}
