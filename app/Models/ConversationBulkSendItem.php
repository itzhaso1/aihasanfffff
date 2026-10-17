<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationBulkSendItem extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'bulk_send_id',
        'workspace_id',
        'conversation_id',
        'message_id',
        'status',
        'error_code',
        'error_message',
    ];

    public function bulkSend(): BelongsTo
    {
        return $this->belongsTo(ConversationBulkSend::class, 'bulk_send_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
