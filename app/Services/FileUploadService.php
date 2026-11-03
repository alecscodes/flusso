<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    /**
     * @param  array<int, mixed>  $files
     * @return Collection<int, TransactionFile>
     */
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

    /**
     * Attach a copy of a file that is already stored on the local disk. Every
     * transaction gets its own copy, so deleting one never takes the file of another.
     */
    public function attachStoredCopy(Transaction $transaction, string $sourcePath, string $originalFilename): TransactionFile
    {
        $disk = Storage::disk('local');
        $path = 'transaction-files/'.Str::uuid()->toString().'.'.pathinfo($sourcePath, PATHINFO_EXTENSION);

        $disk->copy($sourcePath, $path);

        return $transaction->files()->create([
            'filename' => basename($path),
            'original_filename' => $originalFilename,
            'mime_type' => $disk->mimeType($sourcePath) ?: 'application/octet-stream',
            'size' => $disk->size($sourcePath),
            'path' => $path,
        ]);
    }
}
