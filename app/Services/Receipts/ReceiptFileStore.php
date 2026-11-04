<?php

namespace App\Services\Receipts;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where a scanned receipt waits between the scan and the owner's confirmation.
 * Files live in a folder per user under a generated name (the client's file name
 * is never used), and the token handed to the browser is only that name.
 */
class ReceiptFileStore
{
    public const TOKEN_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp)$/';

    private const STALE_AFTER_HOURS = 24;

    /**
     * Keep the upload and return its token. The extension comes from the file's content, not its name.
     */
    public function stash(User $user, UploadedFile $file): string
    {
        $this->pruneStale($user);

        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $token = Str::uuid()->toString().'.'.$extension;

        Storage::disk('local')->putFileAs($this->folder($user), $file, $token);

        return $token;
    }

    /**
     * The stored file's path on the local disk, or null for an unknown token or one that is not this user's.
     */
    public function path(User $user, string $token): ?string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $path = $this->folder($user).'/'.$token;

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk('local')->path($path);
    }

    public function discard(User $user, string $token): void
    {
        $path = $this->path($user, $token);

        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }

    private function folder(User $user): string
    {
        return 'receipt-uploads/'.$user->id;
    }

    /**
     * Scans nobody confirmed are not kept for long.
     */
    private function pruneStale(User $user): void
    {
        $disk = Storage::disk('local');
        $limit = now()->subHours(self::STALE_AFTER_HOURS)->getTimestamp();

        foreach ($disk->files($this->folder($user)) as $path) {
            if ($disk->lastModified($path) < $limit) {
                $disk->delete($path);
            }
        }
    }
}
