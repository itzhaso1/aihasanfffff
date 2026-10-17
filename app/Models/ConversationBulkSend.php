<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationBulkSend extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'id',
        'workspace_id',
        'user_id',
        'content_hash',
        'status',
        'total',
        'queued_count',
        'processed_count',
        'sent_count',
        'failed_count',
        'skipped_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'queued_count' => 'integer',
            'processed_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public $incrementing = false;

    protected $keyType = 'string';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ConversationBulkSendItem::class, 'bulk_send_id');
    }
}
