<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateDocumentDisk
{
    public const DISK = 'local';

    public const LEGACY_DISK = 'public';

    /**
     * @return list<string>
     */
    public static function disks(): array
    {
        return [self::DISK, self::LEGACY_DISK];
    }

    public static function download(string $path, ?string $name = null): StreamedResponse
    {
        foreach (self::disks() as $disk) {
            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->download($path, $name);
            }
        }

        abort(404);
    }

    public static function delete(string $path): void
    {
        if ($path === '') {
            return;
        }

        foreach (self::disks() as $disk) {
            Storage::disk($disk)->delete($path);
        }
    }
}
