<?php

namespace App\Services\Media;

use InvalidArgumentException;

final class MediaSecurityValidator
{
    /** @var list<string> */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'phar', 'exe', 'js', 'sh', 'bat', 'cmd', 'com', 'msi', 'dll', 'vbs', 'ps1',
    ];

    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/aac', 'audio/mp4',
        'video/mp4', 'video/webm', 'video/quicktime',
        'application/pdf',
        'application/octet-stream',
    ];

    public function assertInboundBinary(string $kind, string $detectedMime, int $sizeBytes, ?string $originalFilename = null): void
    {
        $max = (int) config('media.max_bytes.'.$kind, config('media.max_bytes.default'));
        if ($sizeBytes <= 0 || $sizeBytes > $max) {
            throw new InvalidArgumentException('media_size_exceeded');
        }

        $mime = strtolower(trim($detectedMime));
        if ($mime === '' || ! $this->mimeAllowedForKind($kind, $mime)) {
            throw new InvalidArgumentException('media_mime_rejected');
        }

        if ($originalFilename !== null) {
            $this->assertSafeFilename($originalFilename);
        }
    }

    public function assertSafeFilename(string $filename): void
    {
        $normalized = str_replace('\\', '/', trim($filename));
        if ($normalized === '' || str_contains($normalized, '..') || str_contains($normalized, '/')) {
            throw new InvalidArgumentException('media_filename_rejected');
        }

        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('media_extension_rejected');
        }
    }

    public function assertSafeStorageKey(string $key): string
    {
        $normalized = str_replace('\\', '/', trim($key));
        if ($normalized === '' || str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
            throw new InvalidArgumentException('media_storage_key_rejected');
        }

        return $normalized;
    }

    private function mimeAllowedForKind(string $kind, string $mime): bool
    {
        if (in_array($mime, self::ALLOWED_MIMES, true)) {
            return true;
        }

        return match ($kind) {
            'image' => str_starts_with($mime, 'image/'),
            'audio' => str_starts_with($mime, 'audio/'),
            'video' => str_starts_with($mime, 'video/'),
            default => str_starts_with($mime, 'application/') || str_starts_with($mime, 'text/plain'),
        };
    }
}
