<?php

namespace App\Models;

use App\AI\Employees\Models\AiEmployee;
use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Worker\WorkerEngagement;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'customer_id',
    'channel',
    'external_id',
    'status',
    'customer_priority',
    'payment_status',
    'review_status',
    'classification_source',
    'classification_updated_by',
    'classification_updated_at',
    'classification_reason',
    'ai_enabled',
    'handoff_status',
    'handoff_reason',
    'handoff_requested_at',
    'handoff_assigned_at',
    'handoff_resolved_at',
    'assigned_user_id',
    'assigned_worker_engagement_id',
    'ai_employee_id',
    'last_message_at',
    'metadata',
])]
class Conversation extends WorkspaceScopedModel
{
    /** @use HasFactory<ConversationFactory> */
    use BelongsToWorkspace, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'handoff_status' => ConversationHandoffStatus::class,
            'handoff_reason' => ConversationHandoffReason::class,
            'handoff_requested_at' => 'datetime',
            'handoff_assigned_at' => 'datetime',
            'handoff_resolved_at' => 'datetime',
            'classification_updated_at' => 'datetime',
            'last_message_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedWorkerEngagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'assigned_worker_engagement_id');
    }

    public function aiEmployee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class, 'ai_employee_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isOpen(): bool
    {
        return (string) $this->status === 'open';
    }
}
