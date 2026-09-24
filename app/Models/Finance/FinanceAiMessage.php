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
    'conversation_id',
    'turn_seq',
    'user_id',
    'role',
    'body',
    'locale',
    'period_from',
    'period_to',
    'facts_safe',
    'tools_used',
])]
class FinanceAiMessage extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected function casts(): array
    {
        return [
            'turn_seq' => 'integer',
            'period_from' => 'date',
            'period_to' => 'date',
            'facts_safe' => 'array',
            'tools_used' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(FinanceAiConversation::class, 'conversation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(FinanceAiToolCall::class, 'message_id');
    }
}
