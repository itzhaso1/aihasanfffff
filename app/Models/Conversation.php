<?php

namespace App\Models;

use App\AI\Employees\Models\AiEmployee;
use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Worker\WorkerEngagement;
use App\Services\Conversation\ConversationResponderService;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    'responder_type',
    'responder_name',
    'responder_assigned_at',
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
            'responder_assigned_at' => 'datetime',
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

    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function responderLogs(): HasMany
    {
        return $this->hasMany(ConversationResponderLog::class)->orderByDesc('id');
    }

    /**
     * Get live responder payload for inbox and real-time monitoring.
     *
     * @return array{
     *     status: string,
     *     type: string,
     *     name: string,
     *     label: string,
     *     badge_class: string,
     *     dot_class: string,
     *     is_ai_generating: bool,
     *     user_id: int|null,
     *     user_initials: string|null,
     *     assigned_at: string|null,
     *     reason: string|null
     * }
     */
    public function currentResponder(): array
    {
        return app(ConversationResponderService::class)->determineResponder($this);
    }

    /**
     * Live active responder info (who is typing or generating right now, or current responder).
     *
     * @return array<string, mixed>
     */
    public function activeResponder(): array
    {
        return $this->currentResponder();
    }

    /**
     * Resolved assigned employee information (concept A: assigned employee).
     *
     * @return array{id: int|null, name: string|null, type: string}|null
     */
    public function assignedEmployee(): ?array
    {
        if ($this->assigned_worker_engagement_id !== null) {
            $workerEngagement = $this->relationLoaded('assignedWorkerEngagement')
                ? $this->assignedWorkerEngagement
                : $this->assignedWorkerEngagement()->with('workerProfile.user')->first();
            $workerProfile = $workerEngagement?->workerProfile;
            $name = $workerProfile?->display_name ?: ($workerProfile?->user?->name ?: 'موظف مستقل');

            return [
                'type' => 'worker',
                'id' => $workerProfile?->user_id ?? $this->assigned_user_id,
                'name' => $name,
            ];
        }

        if ($this->assigned_user_id !== null) {
            $user = $this->relationLoaded('assignedUser')
                ? $this->assignedUser
                : $this->assignedUser()->first();

            return [
                'type' => 'user',
                'id' => $this->assigned_user_id,
                'name' => $user?->name ?: ($this->responder_name ?: 'موظف خدمة العملاء'),
            ];
        }

        return null;
    }

    /**
     * Resolved last message sender information (concept C: last message sender).
     *
     * @return array{type: string, id: int|null, name: string}|null
     */
    public function lastMessageSender(): ?array
    {
        $last = $this->relationLoaded('lastMessage')
            ? $this->lastMessage
            : ($this->relationLoaded('messages') ? $this->messages->last() : $this->lastMessage);

        if (! $last) {
            return null;
        }

        $last->loadMissing(['user:id,name', 'customer:id,name']);

        if ($last->direction === 'inbound') {
            return [
                'type' => 'customer',
                'id' => $last->customer_id,
                'name' => $last->customer?->name ?: ($this->customer?->name ?: 'العميل'),
            ];
        }

        if ($last->ai_generated) {
            return [
                'type' => 'ai',
                'id' => $this->ai_employee_id,
                'name' => ($this->aiEmployee?->name ?? 'مشعل').' AI',
            ];
        }

        return [
            'type' => 'human',
            'id' => $last->user_id,
            'name' => $last->user?->name ?: 'موظف',
        ];
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

        if ((bool) $this->ai_enabled && $this->handoffStatusEnum() === ConversationHandoffStatus::Resolved) {
            $attributes['handoff_status'] = ConversationHandoffStatus::None;
            $attributes['handoff_reason'] = null;
            $attributes['handoff_requested_at'] = null;
            $attributes['handoff_assigned_at'] = null;
            $attributes['handoff_resolved_at'] = null;
        }

        return $attributes;
    }
}
