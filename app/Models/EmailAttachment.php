<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Fillable([
    'workspace_id',
    'message_id',
    'file_path',
    'disk',
    'file_type',
    'file_size',
])]
class EmailAttachment extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'message_id');
    }

    public function storageDisk(): string
    {
        $disk = trim((string) ($this->disk ?: 'public'));

        return $disk !== '' ? $disk : 'public';
    }

    public function assertSafeStoragePath(): string
    {
        $path = str_replace('\\', '/', trim((string) $this->file_path));
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new RuntimeException('Invalid attachment path.');
        }

        return $path;
    }

    public function deleteStoredFile(): void
    {
        $path = $this->assertSafeStoragePath();
        $disk = Storage::disk($this->storageDisk());
        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
