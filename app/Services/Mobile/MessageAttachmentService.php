<?php

namespace App\Services\Mobile;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\UploadedFile;
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
        'audio/webm',
    ];

    private const MAX_BYTES = 10 * 1024 * 1024;

    public function upload(Message $message, UploadedFile $file, User $actor): MessageAttachment
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('نوع الملف غير مدعوم.');
        }

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'mp3', 'mpeg', 'mp4', 'wav', 'webm'];
        $guessedExtension = strtolower((string) $file->guessExtension());
        if ($guessedExtension === '' || ! in_array($guessedExtension, $allowedExtensions, true)) {
            throw new InvalidArgumentException('نوع الملف غير مدعوم.');
        }

        $clientExtension = strtolower((string) $file->getClientOriginalExtension());
        if (in_array($clientExtension, ['php', 'phtml', 'phar', 'exe', 'js', 'sh', 'bat', 'cmd', 'com'], true)) {
            throw new InvalidArgumentException('نوع الملف غير مدعوم.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('حجم الملف يتجاوز الحد المسموح (10 ميجابايت).');
        }

        $workspaceId = (int) $message->workspace_id;
        $messageId = (int) $message->id;
        $filename = Str::uuid()->toString().'.'.$file->guessExtension();
        $directory = "inbox/{$workspaceId}/{$messageId}";
        $diskName = (string) config('media.disk', 'local');
        $path = $file->storeAs($directory, $filename, $diskName);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('تعذر حفظ المرفق.');
        }

        return MessageAttachment::query()->create([
            'workspace_id' => $workspaceId,
            'message_id' => $messageId,
            'provider' => 'mobile',
            'provider_media_id' => Str::uuid()->toString(),
            'disk' => $diskName,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'kind' => $this->kindFromMime($mime),
            'status' => MessageAttachment::STATUS_STORED,
            'checksum' => hash('sha256', (string) file_get_contents($file->getRealPath())),
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
        $disk = Storage::disk($attachment->disk ?: 'local');

        if ($disk->providesTemporaryUrls()) {
            return $disk->temporaryUrl($path, now()->addMinutes($ttlMinutes));
        }

        return URL::temporarySignedRoute(
            'mobile.v1.attachments.download',
            now()->addMinutes($ttlMinutes),
            [
                'attachment' => $attachment->id,
                'workspace' => (int) $attachment->workspace_id,
            ],
        );
    }

    public function stream(MessageAttachment $attachment)
    {
        $this->assertAuthorized($attachment);
        $path = $this->assertSafePath($attachment->path);
        $disk = Storage::disk($attachment->disk ?: 'local');

        if (! $disk->exists($path)) {
            throw new RuntimeException('المرفق غير موجود.');
        }

        return $disk->download($path, $attachment->original_name);
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
