<?php

namespace App\Services\Conversation;

use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Enums\WorkerActivityEventType;
use App\Enums\WorkerEngagementStatus;
use App\Events\Conversation\ConversationAiResumed;
use App\Events\Conversation\ConversationHandoffActivated;
use App\Events\Conversation\ConversationHandoffAssigned;
use App\Events\Conversation\ConversationHandoffRequested;
use App\Events\Conversation\ConversationHandoffResolved;
use App\Events\Realtime\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Worker\WorkerActivityEventService;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Services\Worker\WorkerInboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ConversationHandoffService
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'none' => ['requested', 'assigned'],
        'requested' => ['queued', 'assigned', 'active', 'resolved'],
        'queued' => ['assigned', 'resolved'],
        'assigned' => ['active', 'queued', 'resolved'],
        'active' => ['resolved'],
        'resolved' => ['none', 'requested'],
    ];

    public function __construct(
        private readonly ConversationHandoffSummaryBuilder $summaryBuilder,
        private readonly AuditLogService $auditLog,
        private readonly WorkerEngagementAuthorization $workerAuthorization,
        private readonly WorkerActivityEventService $workerActivity,
    ) {}

    public function requestFromCustomerPhrase(Conversation $conversation, Message $triggerMessage): bool
    {
        if ($triggerMessage->direction !== 'inbound' || $triggerMessage->message_type !== 'text') {
            return false;
        }

        $status = $this->currentStatus($conversation);
        if ($status->blocksAi() && $status !== ConversationHandoffStatus::Resolved) {
            return false;
        }

        return DB::transaction(function () use ($conversation, $triggerMessage): bool {
            $fresh = $conversation->fresh() ?? $conversation;
            if ($this->currentStatus($fresh)->blocksAi() && $this->currentStatus($fresh) !== ConversationHandoffStatus::Resolved) {
                return false;
            }

            $this->request(
                $fresh,
                ConversationHandoffReason::CustomerRequest,
                $this->baseContext($fresh, 'customer', $triggerMessage),
                queueImmediately: true,
            );

            $this->sendCustomerAcknowledgement($fresh->fresh() ?? $fresh);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function request(
        Conversation $conversation,
        ConversationHandoffReason $reason,
        array $context = [],
        bool $queueImmediately = false,
    ): Conversation {
        return DB::transaction(function () use ($conversation, $reason, $context, $queueImmediately): Conversation {
            $fresh = $conversation->fresh() ?? $conversation;
            $this->assertOpen($fresh);

            $status = $this->currentStatus($fresh);
            if ($status === ConversationHandoffStatus::None || $status === ConversationHandoffStatus::Resolved) {
                $this->transition($fresh, ConversationHandoffStatus::Requested);
                $fresh = $fresh->fresh() ?? $fresh;
            }

            $fresh = $this->applyHandoffPayload($fresh, $reason, $context);

            if ($queueImmediately && $this->currentStatus($fresh) === ConversationHandoffStatus::Requested) {
                $fresh = $this->transition($fresh, ConversationHandoffStatus::Queued);
            }

            $fresh->update(['ai_enabled' => false]);
            $fresh = $this->refreshSummary($fresh->fresh() ?? $fresh);

            event(new ConversationHandoffRequested($fresh, $this->summaryBuilder->build($fresh)));
            $this->publishConversation($fresh);

            Log::info('conversation.handoff.requested', [
                'workspace_id' => (int) $fresh->workspace_id,
                'conversation_id' => (int) $fresh->id,
                'reason' => $reason->value,
                'handoff_status' => $fresh->handoff_status instanceof ConversationHandoffStatus
                    ? $fresh->handoff_status->value
                    : (string) $fresh->handoff_status,
            ]);

            return $fresh->fresh() ?? $fresh;
        });
    }

    public function queue(Conversation $conversation): Conversation
    {
        return DB::transaction(function () use ($conversation): Conversation {
            $fresh = $conversation->fresh() ?? $conversation;
            $this->assertOpen($fresh);
            $fresh = $this->transition($fresh, ConversationHandoffStatus::Queued);
            $fresh->update(['ai_enabled' => false]);

            return $this->publishAndReturn($fresh);
        });
    }

    public function assign(
        Conversation $conversation,
        User $assignee,
        ?WorkerEngagement $workerEngagement = null,
        ?User $actor = null,
    ): Conversation {
        return DB::transaction(function () use ($conversation, $assignee, $workerEngagement, $actor): Conversation {
            $fresh = $conversation->fresh() ?? $conversation;
            $this->assertOpen($fresh);
            if ($workerEngagement instanceof WorkerEngagement) {
                $this->assertWorkerEngagement($fresh, $assignee, $workerEngagement);
            } else {
                $this->assertWorkspaceMember($fresh, $assignee);
            }
            $previousWorkerEngagementId = $fresh->assigned_worker_engagement_id;

            $fresh = $this->transition($fresh, ConversationHandoffStatus::Assigned);
            $fresh->update([
                'assigned_user_id' => $assignee->id,
                'assigned_worker_engagement_id' => $workerEngagement?->id,
                'handoff_assigned_at' => now(),
                'ai_enabled' => false,
            ]);
            $fresh = $this->refreshSummary($fresh->fresh() ?? $fresh);

            event(new ConversationHandoffAssigned($fresh, $assignee));

            if ($workerEngagement instanceof WorkerEngagement) {
                $this->auditLog->log(
                    action: 'worker.inbox.conversation.assigned',
                    entityType: Conversation::class,
                    entityId: $fresh->id,
                    newValues: [
                        'assigned_user_id' => $assignee->id,
                        'assigned_worker_engagement_id' => $workerEngagement->id,
                    ],
                    actor: $actor,
                    meta: [
                        'conversation_id' => $fresh->id,
                        'worker_engagement_id' => $workerEngagement->id,
                        'worker_profile_id' => $workerEngagement->worker_profile_id,
                    ],
                    workspaceId: $fresh->workspace_id,
                );

                $this->workerActivity->recordOperationalEvent(
                    $workerEngagement,
                    $assignee,
                    WorkerActivityEventType::ConversationAssigned,
                    Conversation::class,
                    (int) $fresh->id,
                    [
                        'conversation_id' => $fresh->id,
                        'assigned_by_user_id' => $actor?->id,
                    ],
                );
            } elseif ($previousWorkerEngagementId !== null) {
                $this->auditLog->log(
                    action: 'worker.inbox.conversation.unassigned',
                    entityType: Conversation::class,
                    entityId: $fresh->id,
                    oldValues: [
                        'assigned_worker_engagement_id' => (int) $previousWorkerEngagementId,
                    ],
                    newValues: [
                        'assigned_user_id' => $assignee->id,
                    ],
                    actor: $actor,
                    meta: [
                        'conversation_id' => $fresh->id,
                        'worker_engagement_id' => (int) $previousWorkerEngagementId,
                    ],
                    workspaceId: $fresh->workspace_id,
                );
            }

            return $this->publishAndReturn($fresh);
        });
    }

    public function takeover(Conversation $conversation, User $actor): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor): Conversation {
            $fresh = $conversation->fresh() ?? $conversation;
            $this->assertOpen($fresh);
            $this->assertWorkspaceMember($fresh, $actor);

            if ($this->currentStatus($fresh) === ConversationHandoffStatus::Active
                && (int) $fresh->assigned_user_id === (int) $actor->id) {
                return $fresh;
            }

            $status = $this->currentStatus($fresh);
            if ($status === ConversationHandoffStatus::None) {
                $fresh = $this->request(
                    $fresh,
                    ConversationHandoffReason::AgentRequested,
                    $this->baseContext($fresh, 'staff'),
                    queueImmediately: true,
                );
            } elseif ($fresh->handoff_reason === null) {
                $fresh = $this->applyHandoffPayload($fresh, ConversationHandoffReason::AgentRequested, $this->baseContext($fresh, 'staff'));
            }

            if (in_array($this->currentStatus($fresh), [ConversationHandoffStatus::Requested, ConversationHandoffStatus::Queued], true)) {
                $fresh = $this->assign($fresh, $actor, actor: $actor);
            } elseif ($fresh->assigned_user_id !== $actor->id) {
                $fresh = $this->assign($fresh, $actor, actor: $actor);
            }

            $fresh = $this->transition($fresh->fresh() ?? $fresh, ConversationHandoffStatus::Active);
            $fresh->update(['ai_enabled' => false]);
            $fresh = $this->refreshSummary($fresh->fresh() ?? $fresh);

            event(new ConversationHandoffActivated($fresh, $actor));

            return $this->publishAndReturn($fresh);
        });
    }

    public function resolve(Conversation $conversation, User $actor): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor): Conversation {
            $fresh = $conversation->fresh() ?? $conversation;
            $this->assertOpen($fresh);
            $this->assertWorkspaceMember($fresh, $actor);

            if ($this->currentStatus($fresh) === ConversationHandoffStatus::None) {
                throw new InvalidHandoffTransitionException('No active handoff to resolve.');
            }

            $fresh = $this->transition($fresh, ConversationHandoffStatus::Resolved);
            $fresh->update([
                'handoff_resolved_at' => now(),
                'ai_enabled' => false,
            ]);
            $fresh = $this->appendHistory($fresh);
            $fresh = $this->refreshSummary($fresh->fresh() ?? $fresh);

            event(new ConversationHandoffResolved($fresh, $actor));

            return $this->publishAndReturn($fresh);
        });
    }

    public function resumeAi(Conversation $conversation, User $actor): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor): Conversation {
            $fresh = $conversation->fresh() ?? $conversation;
            $this->assertOpen($fresh);
            $this->assertWorkspaceMember($fresh, $actor);

            if ($this->currentStatus($fresh) !== ConversationHandoffStatus::Resolved
                && $this->currentStatus($fresh)->blocksAi()) {
                throw new InvalidHandoffTransitionException('Handoff must be resolved before resuming AI.');
            }

            if ($this->currentStatus($fresh) === ConversationHandoffStatus::Resolved) {
                $fresh = $this->transition($fresh, ConversationHandoffStatus::None);
            }

            $previousWorkerEngagementId = $fresh->assigned_worker_engagement_id;
            $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
            $metadata['handoff_resumed_at'] = now()->toIso8601String();
            $this->syncLegacyHandoffFlags($metadata, ConversationHandoffStatus::None);

            $fresh->update([
                'ai_enabled' => true,
                'handoff_reason' => null,
                'handoff_requested_at' => null,
                'handoff_assigned_at' => null,
                'handoff_resolved_at' => null,
                'assigned_user_id' => null,
                'assigned_worker_engagement_id' => null,
                'metadata' => $metadata,
            ]);

            $fresh = $this->refreshSummary($fresh->fresh() ?? $fresh);
            event(new ConversationAiResumed($fresh, $actor));

            if ($previousWorkerEngagementId !== null) {
                $this->auditLog->log(
                    action: 'worker.inbox.conversation.unassigned',
                    entityType: Conversation::class,
                    entityId: $fresh->id,
                    oldValues: [
                        'assigned_worker_engagement_id' => (int) $previousWorkerEngagementId,
                    ],
                    newValues: [
                        'assigned_user_id' => null,
                        'ai_enabled' => true,
                    ],
                    actor: $actor,
                    meta: [
                        'conversation_id' => $fresh->id,
                        'worker_engagement_id' => (int) $previousWorkerEngagementId,
                        'handoff' => 'resume_ai',
                    ],
                    workspaceId: $fresh->workspace_id,
                );
            }

            return $this->publishAndReturn($fresh);
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function applyHandoffPayload(
        Conversation $conversation,
        ConversationHandoffReason $reason,
        array $context,
    ): Conversation {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $handoff = is_array($metadata['handoff'] ?? null) ? $metadata['handoff'] : [];

        $handoff = array_merge($handoff, [
            'reason' => $reason->value,
            'requested_by' => (string) ($context['requested_by'] ?? 'system'),
            'source' => (string) ($context['source'] ?? 'chat'),
            'summary' => (string) ($context['summary'] ?? ''),
            'updated_at' => now()->toIso8601String(),
        ]);

        if (isset($context['trigger_message_id'])) {
            $handoff['trigger_message_id'] = (int) $context['trigger_message_id'];
        }

        $catalogRef = $this->catalogReference($conversation);
        if ($catalogRef !== []) {
            $handoff['catalog'] = $catalogRef;
        }

        $metadata['handoff'] = $handoff;
        $this->syncLegacyHandoffFlags($metadata, $this->currentStatus($conversation));

        $updates = [
            'metadata' => $metadata,
            'handoff_reason' => $reason->value,
            'ai_enabled' => false,
        ];

        if ($conversation->handoff_requested_at === null) {
            $updates['handoff_requested_at'] = now();
        }

        $updates['handoff_resolved_at'] = null;

        $conversation->update($updates);

        return $conversation->fresh() ?? $conversation;
    }

    private function transition(Conversation $conversation, ConversationHandoffStatus $to): Conversation
    {
        $from = $this->currentStatus($conversation);
        if ($from === $to) {
            return $conversation;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];
        if (! in_array($to->value, $allowed, true)) {
            throw new InvalidHandoffTransitionException(
                "Invalid handoff transition {$from->value} → {$to->value}."
            );
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $this->syncLegacyHandoffFlags($metadata, $to);

        $conversation->update([
            'handoff_status' => $to->value,
            'metadata' => $metadata,
        ]);

        if ($to === ConversationHandoffStatus::Requested && $conversation->handoff_requested_at === null) {
            $conversation->update(['handoff_requested_at' => now()]);
        }

        return $conversation->fresh() ?? $conversation;
    }

    private function currentStatus(Conversation $conversation): ConversationHandoffStatus
    {
        $value = $conversation->handoff_status;
        if ($value instanceof ConversationHandoffStatus) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return ConversationHandoffStatus::tryFrom($value) ?? ConversationHandoffStatus::None;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        if (($metadata['human_handoff'] ?? false) === true && ! $conversation->ai_enabled) {
            return ConversationHandoffStatus::Queued;
        }

        return ConversationHandoffStatus::None;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function syncLegacyHandoffFlags(array &$metadata, ConversationHandoffStatus $status): void
    {
        $active = $status->blocksAi() && $status !== ConversationHandoffStatus::Resolved;
        $metadata['human_handoff'] = $active;
        $metadata['human_handoff_status'] = match ($status) {
            ConversationHandoffStatus::Requested => 'requested',
            ConversationHandoffStatus::Queued => 'waiting_for_agent',
            ConversationHandoffStatus::Assigned => 'assigned',
            ConversationHandoffStatus::Active => 'active',
            ConversationHandoffStatus::Resolved => 'resolved',
            default => null,
        };
        if ($active && ! isset($metadata['human_handoff_at'])) {
            $metadata['human_handoff_at'] = now()->toIso8601String();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseContext(Conversation $conversation, string $requestedBy, ?Message $trigger = null): array
    {
        return array_filter([
            'requested_by' => $requestedBy,
            'source' => 'chat',
            'trigger_message_id' => $trigger?->id,
            'summary' => $trigger ? mb_substr((string) $trigger->content, 0, 280) : '',
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogReference(Conversation $conversation): array
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $catalog = data_get($metadata, 'catalog');
        if (! is_array($catalog)) {
            return [];
        }

        return array_filter([
            'catalog_id' => data_get($catalog, 'catalog_id'),
            'catalog_item_id' => data_get($catalog, 'catalog_item_id'),
            'variant_id' => data_get($catalog, 'variant_id'),
            'source' => data_get($catalog, 'source'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function refreshSummary(Conversation $conversation): Conversation
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $handoff = is_array($metadata['handoff'] ?? null) ? $metadata['handoff'] : [];
        $handoff['structured'] = $this->summaryBuilder->build($conversation);
        $metadata['handoff'] = $handoff;
        $conversation->update(['metadata' => $metadata]);

        return $conversation->fresh() ?? $conversation;
    }

    private function appendHistory(Conversation $conversation): Conversation
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $history = is_array($metadata['handoff_history'] ?? null) ? $metadata['handoff_history'] : [];
        $history[] = array_merge(
            $this->summaryBuilder->build($conversation),
            ['archived_at' => now()->toIso8601String()],
        );
        $metadata['handoff_history'] = array_slice($history, -20);

        $conversation->update(['metadata' => $metadata]);

        return $conversation->fresh() ?? $conversation;
    }

    private function sendCustomerAcknowledgement(Conversation $conversation): void
    {
        $content = 'تم تحويل المحادثة للفريق، وسيتم الرد عليك من أحد أعضاء الفريق.';

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'message_type' => 'system',
            'content' => $content,
            'ai_generated' => false,
            'metadata' => [
                'handoff_acknowledgement' => true,
            ],
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        app(InboxRealtimePublisher::class)->publishNewMessage(
            $message,
            $conversation->fresh() ?? $conversation,
            ['origin' => 'handoff_ack'],
        );
    }

    private function assertOpen(Conversation $conversation): void
    {
        if (! in_array($conversation->status, ['open'], true)) {
            throw new InvalidArgumentException('Handoff is only allowed on open conversations.');
        }
    }

    private function assertWorkerEngagement(
        Conversation $conversation,
        User $assignee,
        WorkerEngagement $engagement,
    ): void {
        $workerProfile = $engagement->workerProfile;

        if (
            (int) $engagement->workspace_id !== (int) $conversation->workspace_id
            || (int) $workerProfile?->user_id !== (int) $assignee->id
            || ! $engagement->status instanceof WorkerEngagementStatus
            || $engagement->status !== WorkerEngagementStatus::Active
        ) {
            throw new InvalidArgumentException('Worker engagement is not valid for this conversation.');
        }

        $this->workerAuthorization->authorize(
            $assignee,
            $conversation->workspace,
            $engagement,
            WorkerInboxService::READ_CAPABILITY,
        );
    }

    private function assertWorkspaceMember(Conversation $conversation, User $user): void
    {
        $workspace = Workspace::query()->find($conversation->workspace_id);
        if (! $workspace) {
            throw new InvalidArgumentException('Workspace not found.');
        }

        $member = $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->exists();

        if (! $member) {
            throw new InvalidArgumentException('User is not a member of this workspace.');
        }
    }

    private function publishAndReturn(Conversation $conversation): Conversation
    {
        $fresh = $conversation->fresh() ?? $conversation;
        $this->publishConversation($fresh);

        return $fresh;
    }

    private function publishConversation(Conversation $conversation): void
    {
        try {
            event(new ConversationUpdated($conversation));
        } catch (\Throwable) {
            // Realtime is best-effort.
        }
    }
}
