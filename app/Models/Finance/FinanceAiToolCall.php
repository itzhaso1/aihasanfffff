<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'conversation_id',
    'message_id',
    'tool_name',
    'round',
    'arguments_safe',
    'result_safe',
    'permission_result',
    'duration_ms',
    'success',
])]
class FinanceAiToolCall extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'arguments_safe' => 'array',
            'result_safe' => 'array',
            'success' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(FinanceAiConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(FinanceAiMessage::class, 'message_id');
    }
}
