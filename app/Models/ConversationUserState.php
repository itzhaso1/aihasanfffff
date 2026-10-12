<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationUserState extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'user_id',
        'last_read_message_id',
        'last_read_at',
        'muted_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'muted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
