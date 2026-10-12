<?php

namespace App\Models;

use App\Enums\InboxAgentMemoryEvidenceType;
use App\Enums\InboxAgentMemoryScope;
use App\Enums\InboxAgentMemoryStatus;
use App\Enums\InboxAgentMemoryValueType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'customer_id',
    'conversation_id',
    'scope',
    'key',
    'value',
    'value_type',
    'status',
    'confidence',
    'evidence_type',
    'source_message_id',
    'source_conversation_id',
    'first_seen_at',
    'last_confirmed_at',
    'expires_at',
    'metadata',
])]
class InboxAgentMemory extends WorkspaceScopedModel
{
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'confidence' => 'decimal:3',
            'scope' => InboxAgentMemoryScope::class,
            'status' => InboxAgentMemoryStatus::class,
            'value_type' => InboxAgentMemoryValueType::class,
            'evidence_type' => InboxAgentMemoryEvidenceType::class,
            'first_seen_at' => 'datetime',
            'last_confirmed_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /**
     * @param  Builder<InboxAgentMemory>  $query
     * @return Builder<InboxAgentMemory>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', InboxAgentMemoryStatus::Active)
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @param  Builder<InboxAgentMemory>  $query
     * @return Builder<InboxAgentMemory>
     */
    public function scopeCustomerScope(Builder $query): Builder
    {
        return $query->where('scope', InboxAgentMemoryScope::Customer);
    }

    /**
     * @param  Builder<InboxAgentMemory>  $query
     * @return Builder<InboxAgentMemory>
     */
    public function scopeConversationScope(Builder $query): Builder
    {
        return $query->where('scope', InboxAgentMemoryScope::Conversation);
    }

    /**
     * @param  Builder<InboxAgentMemory>  $query
     * @return Builder<InboxAgentMemory>
     */
    public function scopeForCustomer(Builder $query, int $workspaceId, int $customerId): Builder
    {
        return $query
            ->where('workspace_id', $workspaceId)
            ->where('customer_id', $customerId);
    }

    /**
     * @param  Builder<InboxAgentMemory>  $query
     * @return Builder<InboxAgentMemory>
     */
    public function scopeForConversation(Builder $query, int $workspaceId, int $conversationId): Builder
    {
        return $query
            ->where('workspace_id', $workspaceId)
            ->where('conversation_id', $conversationId);
    }
}
