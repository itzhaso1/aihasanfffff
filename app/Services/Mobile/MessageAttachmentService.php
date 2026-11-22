<?php

namespace App\Services\Mobile;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class MessageAttachmentService
{
    /** @var array<int, string> */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'audio/mpeg',
        'audio/mp3',
        'video/mp4',
        'audio/wav',
        'audio/x-wav',
        'video/webm',
        'video/quicktime',
        'audio/webm',
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'audio/aac',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-zip-compressed',
    ];

    private const MAX_BYTES = 20 * 1024 * 1024;

    public function upload(Message $message, UploadedFile $file, User $actor): MessageAttachment
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('تعذر قراءة الملف المرفوع.');
        }

        $mime = $this->normalizeMime((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('نوع الملف غير مدعوم.');
        }

        $allowedExtensions = [
            'jpg', 'jpeg', 'png', 'webp', 'gif',
            'pdf', 'mp3', 'mpeg', 'mp4', 'wav', 'webm', 'mov', 'm4a', 'aac',
            'txt', 'doc', 'docx', 'xls', 'xlsx', 'zip',
        ];
        $guessedExtension = strtolower((string) $file->guessExtension());
        if ($guessedExtension === '' || ! in_array($guessedExtension, $allowedExtensions, true)) {
            // Also check client extension as fallback for modern mime types like heic or docx
            $clientExt = strtolower((string) $file->getClientOriginalExtension());
            if ($clientExt === '' || ! in_array($clientExt, $allowedExtensions, true)) {
                throw new InvalidArgumentException('نوع الملف غير مدعوم.');
            }
            $guessedExtension = $clientExt;
        }

        $clientExtension = strtolower((string) $file->getClientOriginalExtension());
        if (in_array($clientExtension, ['php', 'phtml', 'phar', 'exe', 'js', 'sh', 'bat', 'cmd', 'com', 'msi', 'vbs'], true)) {
            throw new InvalidArgumentException('نوع الملف غير مدعوم لأسباب أمنية.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('حجم الملف يتجاوز الحد المسموح (20 ميجابايت).');
        }

        $workspaceId = (int) $message->workspace_id;
        $messageId = (int) $message->id;
        $extension = $guessedExtension ?: 'bin';
        $filename = Str::uuid()->toString().'.'.$extension;
        $directory = "inbox/{$workspaceId}/{$messageId}";
        $diskName = (string) config('media.disk', 'local');
        $path = $file->storeAs($directory, $filename, $diskName);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('تعذر حفظ المرفق.');
        }

        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $dimensions = @getimagesize($file->getRealPath());
            if (is_array($dimensions)) {
                $width = (int) ($dimensions[0] ?? null) ?: null;
                $height = (int) ($dimensions[1] ?? null) ?: null;
            }
        }

        Log::info('chat.attachment.upload', [
            'received' => true,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'stored_path' => $path,
            'disk' => $diskName,
            'workspace_id' => $workspaceId,
            'message_id' => $messageId,
            'width' => $width,
            'height' => $height,
        ]);

        return MessageAttachment::query()->create([
            'workspace_id' => $workspaceId,
            'message_id' => $messageId,
            'provider' => 'web_inbox',
            'provider_media_id' => Str::uuid()->toString(),
            'disk' => $diskName,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'kind' => $this->kindFromMime($mime),
            'status' => MessageAttachment::STATUS_STORED,
            'checksum' => hash('sha256', (string) file_get_contents($file->getRealPath())),
            'width' => $width,
            'height' => $height,
        ]);
    }

    public function workspaceDownloadUrl(MessageAttachment $attachment): string
    {
        return route('workspace.message-attachments.download', ['attachment' => $attachment->id]);
    }

    public function downloadUrl(MessageAttachment $attachment, int $ttlMinutes = 30): string
    {
        $this->assertAuthorized($attachment);

        $path = $this->assertSafePath($attachment->path);
        $diskName = $attachment->disk ?: 'local';
        $disk = Storage::disk($diskName);

        if ($this->usesCloudTemporaryUrls($diskName) && $disk->providesTemporaryUrls()) {
            return $disk->temporaryUrl($path, now()->addMinutes($ttlMinutes));
        }

        return $this->signedDownloadUrl($attachment, $ttlMinutes);
    }

    public function stream(MessageAttachment $attachment)
    {
        $this->assertAuthorized($attachment);
        $path = $this->assertSafePath($attachment->path);
        $disk = Storage::disk($attachment->disk ?: 'local');

        if (! $disk->exists($path)) {
            throw new RuntimeException('المرفق غير موجود.');
        }

        $mime = $this->normalizeMime((string) ($attachment->mime_type ?: $disk->mimeType($path) ?: 'application/octet-stream'));
        $inline = str_starts_with($mime, 'image/');

        return $disk->response(
            $path,
            $attachment->original_name,
            ['Content-Type' => $mime],
            $inline ? 'inline' : 'attachment',
        );
    }

    public function findOwned(int $attachmentId, int $workspaceId): MessageAttachment
    {
        $attachment = MessageAttachment::withoutGlobalScopes()
            ->whereKey($attachmentId)
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $attachment) {
            throw new RuntimeException('المرفق غير موجود.');
        }

        return $attachment;
    }

    private function assertAuthorized(MessageAttachment $attachment): void
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();
        if ($workspaceId === null || (int) $workspaceId !== (int) $attachment->workspace_id) {
            throw new RuntimeException('غير مصرح بالوصول إلى هذا المرفق.');
        }
    }

    private function assertSafePath(?string $path): string
    {
        $normalized = str_replace('\\', '/', trim((string) $path));
        if ($normalized === '' || str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
            throw new RuntimeException('مسار المرفق غير صالح.');
        }

        return $normalized;
    }

    private function usesCloudTemporaryUrls(string $diskName): bool
    {
        $driver = (string) config("filesystems.disks.{$diskName}.driver", $diskName);

        return in_array($driver, ['s3', 's3-compatible', 'gcs', 'azure'], true);
    }

    private function signedDownloadUrl(MessageAttachment $attachment, int $ttlMinutes): string
    {
        $generate = fn (): string => URL::temporarySignedRoute(
            'mobile.v1.attachments.download',
            now()->addMinutes($ttlMinutes),
            [
                'attachment' => $attachment->id,
                'workspace' => (int) $attachment->workspace_id,
            ],
        );

        $request = request();
        $origin = $request?->getSchemeAndHttpHost();
        $configured = rtrim((string) config('app.url'), '/');
        if (! is_string($origin) || $origin === '' || $origin === $configured) {
            return $generate();
        }

        URL::useOrigin($origin);
        try {
            return $generate();
        } finally {
            if ($configured !== '') {
                URL::useOrigin($configured);
            }
        }
    }

    private function normalizeMime(string $mime): string
    {
        $normalized = strtolower(trim($mime));

        return match ($normalized) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            'audio/x-m4a', 'audio/m4a' => 'audio/mp4',
            default => $normalized,
        };
    }

    private function kindFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return 'file';
    }
}
