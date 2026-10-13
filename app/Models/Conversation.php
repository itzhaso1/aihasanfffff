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

    public function handoffStatusEnum(): ConversationHandoffStatus
    {
        $value = $this->handoff_status;
        if ($value instanceof ConversationHandoffStatus) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return ConversationHandoffStatus::tryFrom($value) ?? ConversationHandoffStatus::None;
        }

        return ConversationHandoffStatus::None;
    }

    /**
     * True when this conversation is in a state where AI auto-reply may run
     * (open, flag on, and handoff `none`). `resolved` is not a live AI state
     * even if `ai_enabled` was left true on a legacy row. Workspace-level AI
     * settings are applied separately by ConversationAiEligibilityService.
     */
    public function isAiReplying(): bool
    {
        return $this->isOpen()
            && (bool) $this->ai_enabled
            && $this->handoffStatusEnum()->allowsAiAutoReply();
    }

    /**
     * New customer messages bring archived/closed threads back to the open inbox
     * so auto-reply can run when AI is enabled.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function attributesForInboundCustomerMessage(array $attributes): array
    {
        if (! $this->isOpen() && in_array((string) $this->status, ['closed', 'archived'], true)) {
            $attributes['status'] = 'open';
        }

        return $attributes;
    }
}
