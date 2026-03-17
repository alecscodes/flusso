<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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

    public function deleteFile(TransactionFile $file): bool
    {
        try {
            Storage::disk('local')->delete($file->path);

            return $file->delete();
        } catch (\Exception $e) {
            report($e);

            return false;
        }
    }
}
