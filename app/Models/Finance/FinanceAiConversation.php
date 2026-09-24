<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'user_id',
    'status',
    'title',
    'locale',
    'last_period_from',
    'last_period_to',
    'last_metric_names',
    'context_entities',
    'gemini_interaction_id',
    'processing_started_at',
    'last_message_at',
    'last_turn_seq',
])]
class FinanceAiConversation extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'last_period_from' => 'date',
            'last_period_to' => 'date',
            'last_metric_names' => 'array',
            'context_entities' => 'array',
            'processing_started_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_turn_seq' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FinanceAiMessage::class, 'conversation_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(FinanceAiToolCall::class, 'conversation_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOwnedBy(int $workspaceId, int $userId): bool
    {
        return (int) $this->workspace_id === $workspaceId && (int) $this->user_id === $userId;
    }
}
