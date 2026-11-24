<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Worker\WorkerEngagement;
use App\Services\AI\Inbox\Memory\ConversationFactsMemory;
use App\Services\AI\Inbox\Memory\ConversationRecentMessagesCache;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'conversation_id',
    'customer_id',
    'user_id',
    'worker_engagement_id',
    'direction',
    'message_type',
    'content',
    'external_message_id',
    'ai_generated',
    'delivery_status',
    'delivered_at',
    'read_at',
    'metadata',
])]
class Message extends WorkspaceScopedModel
{
    /** @use HasFactory<MessageFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'ai_generated' => 'boolean',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::created(function (Message $message): void {
            if ($message->workspace_id !== null && $message->conversation_id !== null) {
                try {
                    app(ConversationRecentMessagesCache::class)->push(
                        (int) $message->workspace_id,
                        (int) $message->conversation_id,
                        $message,
                    );
                    app(ConversationFactsMemory::class)->observeMessage($message);
                } catch (\Throwable) {
                }
            }
        });

        static::updated(function (Message $message): void {
            if ($message->workspace_id !== null && $message->conversation_id !== null) {
                try {
                    app(ConversationRecentMessagesCache::class)->invalidate(
                        (int) $message->workspace_id,
                        (int) $message->conversation_id,
                    );
                } catch (\Throwable) {
                }
            }
        });

        static::deleted(function (Message $message): void {
            if ($message->workspace_id !== null && $message->conversation_id !== null) {
                try {
                    app(ConversationRecentMessagesCache::class)->invalidate(
                        (int) $message->workspace_id,
                        (int) $message->conversation_id,
                    );
                } catch (\Throwable) {
                }
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workerEngagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
