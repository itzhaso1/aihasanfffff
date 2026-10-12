<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DOWNLOADING = 'downloading';

    public const STATUS_STORED = 'stored';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'workspace_id',
        'message_id',
        'provider',
        'provider_media_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'kind',
        'status',
        'thumbnail_path',
        'checksum',
        'width',
        'height',
        'duration_ms',
        'provider_metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'provider_metadata' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isReadyForDownload(): bool
    {
        return $this->status === self::STATUS_STORED
            && is_string($this->path)
            && $this->path !== '';
    }
}
