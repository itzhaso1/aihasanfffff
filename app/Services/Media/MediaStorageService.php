<?php

namespace App\Services\Media;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class MediaStorageService
{
    public function __construct(
        private readonly MediaSecurityValidator $security,
    ) {}

    public function diskName(): string
    {
        return (string) config('media.disk', 'local');
    }

    /**
     * @return array{disk:string,path:string,checksum:string,size_bytes:int,mime_type:string}
     */
    public function storeBinary(
        int $workspaceId,
        int $messageId,
        string $kind,
        string $binary,
        string $detectedMime,
        ?string $originalFilename = null,
    ): array {
        $size = strlen($binary);
        $this->security->assertInboundBinary($kind, $detectedMime, $size, $originalFilename);

        $extension = $this->extensionForMime($detectedMime, $originalFilename);
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $this->security->assertSafeStorageKey(
            "inbox/{$workspaceId}/{$messageId}/{$filename}",
        );

        $disk = Storage::disk($this->diskName());
        if (! $disk->put($path, $binary)) {
            throw new RuntimeException('media_store_failed');
        }

        return [
            'disk' => $this->diskName(),
            'path' => $path,
            'checksum' => hash('sha256', $binary),
            'size_bytes' => $size,
            'mime_type' => $detectedMime,
        ];
    }

    public function deleteAttachmentObject(MessageAttachment $attachment): void
    {
        if (! is_string($attachment->path) || $attachment->path === '') {
            return;
        }

        $path = $this->security->assertSafeStorageKey($attachment->path);
        $disk = Storage::disk($attachment->disk ?: $this->diskName());
        if ($disk->exists($path)) {
            $disk->delete($path);
        }

        if (is_string($attachment->thumbnail_path) && $attachment->thumbnail_path !== '') {
            $thumb = $this->security->assertSafeStorageKey($attachment->thumbnail_path);
            if ($disk->exists($thumb)) {
                $disk->delete($thumb);
            }
        }
    }

    private function extensionForMime(string $mime, ?string $originalFilename): string
    {
        if ($originalFilename !== null) {
            $this->security->assertSafeFilename($originalFilename);
            $fromName = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            if ($fromName !== '' && ! in_array($fromName, ['php', 'exe', 'js'], true)) {
                return $fromName;
            }
        }

        return match (true) {
            str_starts_with($mime, 'image/jpeg') => 'jpg',
            str_starts_with($mime, 'image/png') => 'png',
            str_starts_with($mime, 'image/webp') => 'webp',
            str_starts_with($mime, 'image/gif') => 'gif',
            str_starts_with($mime, 'audio/') => 'mp3',
            str_starts_with($mime, 'video/') => 'mp4',
            $mime === 'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
