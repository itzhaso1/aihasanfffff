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
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Conversation\Exceptions\HandoffConflictException;
use App\Services\Conversation\Exceptions\InvalidHandoffTransitionException;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Worker\WorkerActivityEventService;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Services\Worker\WorkerInboxService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class ConversationHandoffService
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'none' => ['requested', 'assigned', 'active'],
        'requested' => ['queued', 'assigned', 'active', 'resolved', 'none'],
        'queued' => ['assigned', 'active', 'resolved', 'none'],
        'assigned' => ['active', 'queued', 'requested', 'resolved', 'none'],
        'active' => ['resolved', 'queued', 'requested', 'assigned', 'none'],
        'resolved' => ['none', 'requested', 'queued', 'assigned', 'active'],
    ];

    public function __construct(
        private readonly ConversationHandoffSummaryBuilder $summaryBuilder,
        private readonly AuditLogService $auditLog,
        private readonly WorkerEngagementAuthorization $workerAuthorization,
        private readonly WorkerActivityEventService $workerActivity,
        private readonly ConversationResponderService $responderService,
    ) {}

    public function requestFromCustomerPhrase(Conversation $conversation, Message $triggerMessage): bool
    {
        if ($triggerMessage->direction !== 'inbound' || trim((string) $triggerMessage->content) === '') {
            return false;
        }

        $status = $this->currentStatus($conversation);
        if ($status->blocksAi() && $status !== ConversationHandoffStatus::Resolved) {
            return false;
        }

        $handoffNote = DB::transaction(function () use ($conversation, $triggerMessage): ?Message {
            $fresh = $conversation->fresh() ?? $conversation;
            if ($this->currentStatus($fresh)->blocksAi() && $this->currentStatus($fresh) !== ConversationHandoffStatus::Resolved) {
                return null;
            }

            $fresh = $this->performRequest(
                $fresh,
                ConversationHandoffReason::CustomerRequest,
                $this->baseContext($fresh, 'customer', $triggerMessage),
                queueImmediately: true,
            );

            return $this->createInternalHandoffNote($fresh);
        });

        if (! $handoffNote instanceof Message) {
            return false;
        }

        $this->publishConversation($conversation->fresh() ?? $conversation);
        app(InboxRealtimePublisher::class)->publishNewMessage(
            $handoffNote,
            $conversation->fresh() ?? $conversation,
            ['origin' => 'handoff_ack'],
        );

        return true;
    }

    public function requestStaffReview(
        Conversation $conversation,
        Message $triggerMessage,
        ConversationHandoffReason $reason,
    ): bool {
        if ($triggerMessage->direction !== 'inbound' || $triggerMessage->message_type !== 'text') {
            return false;
        }

        $status = $this->currentStatus($conversation);
        if ($status->blocksAi()) {
            return false;
        }

        $handoffNote = DB::transaction(function () use ($conversation, $triggerMessage, $reason): ?Message {
            $fresh = $conversation->fresh() ?? $conversation;
            if ($this->currentStatus($fresh)->blocksAi()) {
                return null;
            }

            $fresh = $this->performRequest(
                $fresh,
                $reason,
                $this->baseContext($fresh, 'staff_review', $triggerMessage),
                queueImmediately: true,
            );

            return $this->createInternalHandoffNote($fresh);
        });

        if (! $handoffNote instanceof Message) {
            return false;
        }

        $this->publishConversation($conversation->fresh() ?? $conversation);
        app(InboxRealtimePublisher::class)->publishNewMessage(
            $handoffNote,
            $conversation->fresh() ?? $conversation,
            ['origin' => 'handoff_ack'],
        );

        return true;
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
        $fresh = DB::transaction(fn (): Conversation => $this->performRequest(
            $conversation->fresh() ?? $conversation,
            $reason,
            $context,
            $queueImmediately,
        ));

        return $this->publishAndReturn($fresh);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function performRequest(
        Conversation $conversation,
        ConversationHandoffReason $reason,
        array $context = [],
        bool $queueImmediately = false,
    ): Conversation {
        $fresh = $conversation->fresh() ?? $conversation;
        $this->assertOpen($fresh);

        $status = $this->currentStatus($fresh);
        if (in_array($status, [ConversationHandoffStatus::None, ConversationHandoffStatus::Resolved, ConversationHandoffStatus::Active, ConversationHandoffStatus::Assigned], true)) {
            if ($status !== ConversationHandoffStatus::Requested && $status !== ConversationHandoffStatus::Queued) {
                $this->transition($fresh, ConversationHandoffStatus::Requested);
                $fresh = $fresh->fresh() ?? $fresh;
            }
        }

        $fresh = $this->applyHandoffPayload($fresh, $reason, $context);

        if ($queueImmediately && $this->currentStatus($fresh) === ConversationHandoffStatus::Requested) {
            $fresh = $this->transition($fresh, ConversationHandoffStatus::Queued);
        }

        $fresh->update([
            'ai_enabled' => false,
            'assigned_user_id' => null,
            'assigned_worker_engagement_id' => null,
            'handoff_assigned_at' => null,
            'responder_type' => ConversationResponderService::TYPE_NONE,
            'responder_name' => 'فريق العمل (بانتظار الاستلام)',
            'responder_assigned_at' => now(),
        ]);
        $fresh = $this->refreshSummary($fresh->fresh() ?? $fresh);

        $this->responderService->recordTransition(
            $fresh,
            ConversationResponderService::TYPE_NONE,
            null,
            'handoff_requested',
            (string) ($context['requested_by'] ?? 'customer'),
            reason: $reason->value,
        );

        event(new ConversationHandoffRequested($fresh, $this->summaryBuilder->build($fresh)));

        Log::info('conversation.handoff.requested', [
            'workspace_id' => (int) $fresh->workspace_id,
            'conversation_id' => (int) $fresh->id,
            'reason' => $reason->value,
            'handoff_status' => $fresh->handoff_status instanceof ConversationHandoffStatus
                ? $fresh->handoff_status->value
                : (string) $fresh->handoff_status,
        ]);

        return $fresh->fresh() ?? $fresh;
    }

    public function queue(Conversation $conversation): Conversation
    {
        $fresh = DB::transaction(function () use ($conversation): Conversation {
            $fresh = $conversation->fresh() ?? $conversation;
            $this->assertOpen($fresh);
            $fresh = $this->transition($fresh, ConversationHandoffStatus::Queued);
            $fresh->update(['ai_enabled' => false]);

            return $fresh->fresh() ?? $fresh;
        });

        return $this->publishAndReturn($fresh);
    }

    public function assign(
        Conversation $conversation,
        User $assignee,
        ?WorkerEngagement $workerEngagement = null,
        ?User $actor = null,
    ): Conversation {
        $fresh = app(WorkspaceContext::class)->runForId(
            (int) $conversation->workspace_id,
            fn (): Conversation => DB::transaction(fn (): Conversation => $this->performAssign(
                $conversation->fresh() ?? $conversation,
                $assignee,
                $workerEngagement,
                $actor,
            )),
        );

        return $this->publishAndReturn($fresh);
    }

    private function performAssign(
        Conversation $conversation,
        User $assignee,
        ?WorkerEngagement $workerEngagement = null,
        ?User $actor = null,
    ): Conversation {
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

        $this->responderService->recordTransition(
            $fresh,
            ConversationResponderService::TYPE_HUMAN,
            $assignee,
            'assigned_to_human',
            'user',
            $actor,
            'إسناد المحادثة إلى موظف',
        );

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

        return $fresh->fresh() ?? $fresh;
    }

    public function takeover(
        Conversation $conversation,
        User $actor,
        ?int $expectedHandoffVersion = null,
    ): Conversation {
        $didMutate = false;

        $fresh = DB::transaction(function () use ($conversation, $actor, $expectedHandoffVersion, &$didMutate): Conversation {
            $fresh = Conversation::withoutGlobalScopes()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOpen($fresh);

            $actorContext = $this->resolveHandoffActorContext($fresh, $actor);
            $currentVersion = (int) ($fresh->handoff_version ?? 1);
            $currentStatus = $this->currentStatus($fresh);

            if ($expectedHandoffVersion !== null && $expectedHandoffVersion > 0 && $currentVersion !== $expectedHandoffVersion) {
                throw new HandoffConflictException(
                    'تغيرت حالة المحادثة للتو بواسطة إجراء آخر. يرجى تحديث الحالة والمحاولة مرة أخرى.',
                    $currentVersion,
                    $fresh->assigned_user_id ? (int) $fresh->assigned_user_id : null,
                    $this->resolveCurrentOwnerName($fresh),
                );
            }

            if ($currentStatus === ConversationHandoffStatus::Active
                && (int) $fresh->assigned_user_id === (int) $actor->id) {
                return $fresh;
            }

            if (in_array($currentStatus, [ConversationHandoffStatus::Active, ConversationHandoffStatus::Assigned], true)
                && $fresh->assigned_user_id !== null
                && (int) $fresh->assigned_user_id !== (int) $actor->id
                && ! $actorContext['isSupervisor']) {
                $ownerName = $this->resolveCurrentOwnerName($fresh) ?? 'موظف آخر';

                throw new HandoffConflictException(
                    "المحادثة قيد المتابعة حالياً من قِبل {$ownerName}.",
                    $currentVersion,
                    (int) $fresh->assigned_user_id,
                    $ownerName,
                );
            }

            $didMutate = true;
            $now = now();
            $previousWorkerEngagementId = $fresh->assigned_worker_engagement_id;
            $workerEngagement = $actorContext['engagement'];
            $displayName = $actorContext['displayName'];
            $reason = $fresh->handoff_reason instanceof ConversationHandoffReason
                ? $fresh->handoff_reason
                : ConversationHandoffReason::AgentRequested;

            $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
            $handoffMeta = is_array($metadata['handoff'] ?? null) ? $metadata['handoff'] : [];
            if (! isset($handoffMeta['reason']) || $fresh->handoff_reason === null) {
                $handoffMeta = array_merge($handoffMeta, [
                    'reason' => $reason->value,
                    'requested_by' => $actorContext['actorType'] === 'worker' ? 'worker' : 'staff',
                    'source' => 'chat',
                    'updated_at' => $now->toIso8601String(),
                ]);
            }
            $handoffMeta['updated_at'] = $now->toIso8601String();
            $this->syncLegacyHandoffFlags($metadata, ConversationHandoffStatus::Active);

            $fresh->fill([
                'handoff_status' => ConversationHandoffStatus::Active->value,
                'handoff_reason' => $reason->value,
                'handoff_requested_at' => $fresh->handoff_requested_at ?? $now,
                'handoff_assigned_at' => $fresh->handoff_assigned_at ?? $now,
                'handoff_resolved_at' => null,
                'handoff_version' => $currentVersion + 1,
                'ai_enabled' => false,
                'assigned_user_id' => $actor->id,
                'assigned_worker_engagement_id' => $workerEngagement?->id,
                'responder_type' => ConversationResponderService::TYPE_HUMAN,
                'responder_name' => $displayName,
                'responder_assigned_at' => $now,
            ]);

            $handoffMeta['structured'] = $this->summaryBuilder->build($fresh);
            $metadata['handoff'] = $handoffMeta;
            $fresh->metadata = $metadata;
            $fresh->save();

            Cache::forget("conversation:{$fresh->id}:ai_generating");

            $this->responderService->recordTransition(
                $fresh,
                ConversationResponderService::TYPE_HUMAN,
                $actor,
                'takeover',
                $actorContext['actorType'],
                $actor,
                'تولى الموظف الرد على المحادثة',
                skipConversationUpdate: true,
                resolvedNewName: $displayName,
            );

            if ($workerEngagement instanceof WorkerEngagement) {
                $this->auditLog->log(
                    action: 'worker.inbox.conversation.assigned',
                    entityType: Conversation::class,
                    entityId: $fresh->id,
                    oldValues: [
                        'assigned_user_id' => $fresh->getOriginal('assigned_user_id'),
                        'assigned_worker_engagement_id' => $previousWorkerEngagementId,
                    ],
                    newValues: [
                        'assigned_user_id' => $actor->id,
                        'assigned_worker_engagement_id' => $workerEngagement->id,
                        'handoff_status' => ConversationHandoffStatus::Active->value,
                    ],
                    actor: $actor,
                    meta: [
                        'conversation_id' => $fresh->id,
                        'worker_engagement_id' => $workerEngagement->id,
                        'worker_profile_id' => $workerEngagement->worker_profile_id,
                        'handoff' => 'takeover',
                    ],
                    workspaceId: $fresh->workspace_id,
                );

                if ((int) $previousWorkerEngagementId !== (int) $workerEngagement->id) {
                    $this->workerActivity->recordOperationalEvent(
                        $workerEngagement,
                        $actor,
                        WorkerActivityEventType::ConversationAssigned,
                        Conversation::class,
                        (int) $fresh->id,
                        [
                            'conversation_id' => $fresh->id,
                            'handoff' => 'takeover',
                        ],
                    );
                }
            } elseif ($previousWorkerEngagementId !== null) {
                $this->auditLog->log(
                    action: 'worker.inbox.conversation.unassigned',
                    entityType: Conversation::class,
                    entityId: $fresh->id,
                    oldValues: [
                        'assigned_worker_engagement_id' => (int) $previousWorkerEngagementId,
                    ],
                    newValues: [
                        'assigned_user_id' => $actor->id,
                    ],
                    actor: $actor,
                    meta: [
                        'conversation_id' => $fresh->id,
                        'worker_engagement_id' => (int) $previousWorkerEngagementId,
                        'handoff' => 'takeover',
                    ],
                    workspaceId: $fresh->workspace_id,
                );
            }

            event(new ConversationHandoffActivated($fresh, $actor));

            return $fresh;
        });

        if (! $didMutate) {
            return $fresh;
        }

        return $this->publishAndReturn($fresh, alreadyFresh: true);
    }

    public function resolve(
        Conversation $conversation,
        User $actor,
        ?int $expectedHandoffVersion = null,
    ): Conversation {
        $fresh = DB::transaction(function () use ($conversation, $actor, $expectedHandoffVersion): Conversation {
            $fresh = Conversation::withoutGlobalScopes()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOpen($fresh);
            $actorContext = $this->resolveHandoffActorContext($fresh, $actor);

            $currentVersion = (int) ($fresh->handoff_version ?? 1);
            if ($expectedHandoffVersion !== null && $expectedHandoffVersion > 0 && $currentVersion !== $expectedHandoffVersion) {
                throw new HandoffConflictException(
                    'تغيرت حالة المحادثة للتو. يرجى المحاولة مرة أخرى.',
                    $currentVersion,
                    $fresh->assigned_user_id ? (int) $fresh->assigned_user_id : null,
                    $this->resolveCurrentOwnerName($fresh),
                );
            }

            $currentStatus = $this->currentStatus($fresh);
            if ($currentStatus === ConversationHandoffStatus::None) {
                throw new InvalidHandoffTransitionException('No active handoff to resolve.');
            }

            if (in_array($currentStatus, [ConversationHandoffStatus::Active, ConversationHandoffStatus::Assigned], true)
                && $fresh->assigned_user_id !== null
                && (int) $fresh->assigned_user_id !== (int) $actor->id
                && ! $actorContext['isSupervisor']) {
                $ownerName = $this->resolveCurrentOwnerName($fresh) ?? 'موظف آخر';

                throw new HandoffConflictException(
                    "المحادثة قيد المتابعة حالياً من قِبل {$ownerName}.",
                    $currentVersion,
                    (int) $fresh->assigned_user_id,
                    $ownerName,
                );
            }

            $now = now();
            $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
            $this->syncLegacyHandoffFlags($metadata, ConversationHandoffStatus::Resolved);

            $fresh->fill([
                'handoff_status' => ConversationHandoffStatus::Resolved->value,
                'handoff_resolved_at' => $now,
                'handoff_version' => $currentVersion + 1,
                'ai_enabled' => false,
                'responder_type' => ConversationResponderService::TYPE_NONE,
                'responder_name' => '—',
                'responder_assigned_at' => $now,
                'metadata' => $metadata,
            ]);

            $history = is_array($metadata['handoff_history'] ?? null) ? $metadata['handoff_history'] : [];
            $history[] = array_merge(
                $this->summaryBuilder->build($fresh),
                ['archived_at' => $now->toIso8601String()],
            );
            $metadata['handoff_history'] = array_slice($history, -20);
            $handoffMeta = is_array($metadata['handoff'] ?? null) ? $metadata['handoff'] : [];
            $handoffMeta['structured'] = $this->summaryBuilder->build($fresh);
            $metadata['handoff'] = $handoffMeta;
            $fresh->metadata = $metadata;
            $fresh->save();

            $this->responderService->recordTransition(
                $fresh,
                ConversationResponderService::TYPE_NONE,
                null,
                'resolved',
                $actorContext['actorType'],
                $actor,
                'إنهاء متابعة الموظف',
                skipConversationUpdate: true,
                resolvedNewName: '—',
            );

            event(new ConversationHandoffResolved($fresh, $actor));

            return $fresh;
        });

        return $this->publishAndReturn($fresh, alreadyFresh: true);
    }

    public function resumeAi(
        Conversation $conversation,
        User $actor,
        ?int $expectedHandoffVersion = null,
    ): Conversation {
        $didMutate = false;

        $fresh = DB::transaction(function () use ($conversation, $actor, $expectedHandoffVersion, &$didMutate): Conversation {
            $fresh = Conversation::withoutGlobalScopes()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertOpen($fresh);
            $actorContext = $this->resolveHandoffActorContext($fresh, $actor);

            $currentVersion = (int) ($fresh->handoff_version ?? 1);
            if ($expectedHandoffVersion !== null && $expectedHandoffVersion > 0 && $currentVersion !== $expectedHandoffVersion) {
                throw new HandoffConflictException(
                    'تغيرت حالة المحادثة للتو بواسطة إجراء آخر. يرجى تحديث الحالة والمحاولة مرة أخرى.',
                    $currentVersion,
                    $fresh->assigned_user_id ? (int) $fresh->assigned_user_id : null,
                    $this->resolveCurrentOwnerName($fresh),
                );
            }

            $currentStatus = $this->currentStatus($fresh);
            if ($currentStatus === ConversationHandoffStatus::None
                && (bool) $fresh->ai_enabled
                && $fresh->assigned_user_id === null
                && $fresh->assigned_worker_engagement_id === null) {
                return $fresh;
            }

            if (in_array($currentStatus, [ConversationHandoffStatus::Active, ConversationHandoffStatus::Assigned], true)
                && $fresh->assigned_user_id !== null
                && (int) $fresh->assigned_user_id !== (int) $actor->id
                && ! $actorContext['isSupervisor']) {
                $ownerName = $this->resolveCurrentOwnerName($fresh) ?? 'موظف آخر';

                throw new HandoffConflictException(
                    "لا يمكن تحويل المحادثة للذكاء الاصطناعي لأنها قيد المتابعة من قِبل {$ownerName}.",
                    $currentVersion,
                    (int) $fresh->assigned_user_id,
                    $ownerName,
                );
            }

            $didMutate = true;
            $now = now();
            $previousWorkerEngagementId = $fresh->assigned_worker_engagement_id;
            $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
            $metadata['handoff_resumed_at'] = $now->toIso8601String();
            $this->syncLegacyHandoffFlags($metadata, ConversationHandoffStatus::None);

            $aiName = $fresh->ai_employee_id !== null
                ? ($fresh->aiEmployee?->name ?? 'مشعل').' AI'
                : 'مشعل AI';

            $fresh->fill([
                'handoff_status' => ConversationHandoffStatus::None->value,
                'ai_enabled' => true,
                'handoff_reason' => null,
                'handoff_requested_at' => null,
                'handoff_assigned_at' => null,
                'handoff_resolved_at' => null,
                'handoff_version' => $currentVersion + 1,
                'assigned_user_id' => null,
                'assigned_worker_engagement_id' => null,
                'responder_type' => ConversationResponderService::TYPE_AI,
                'responder_name' => $aiName,
                'responder_assigned_at' => $now,
            ]);

            $handoffMeta = is_array($metadata['handoff'] ?? null) ? $metadata['handoff'] : [];
            $handoffMeta['structured'] = $this->summaryBuilder->build($fresh);
            $metadata['handoff'] = $handoffMeta;
            $fresh->metadata = $metadata;
            $fresh->save();

            $this->responderService->recordTransition(
                $fresh,
                ConversationResponderService::TYPE_AI,
                null,
                'returned_to_ai',
                $actorContext['actorType'],
                $actor,
                'إعادة المحادثة إلى مشعل AI',
                skipConversationUpdate: true,
                resolvedNewName: $aiName,
            );

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
            } elseif ($actorContext['engagement'] instanceof WorkerEngagement) {
                $this->auditLog->log(
                    action: 'worker.inbox.conversation.returned_to_ai',
                    entityType: Conversation::class,
                    entityId: $fresh->id,
                    newValues: [
                        'assigned_user_id' => null,
                        'ai_enabled' => true,
                    ],
                    actor: $actor,
                    meta: [
                        'conversation_id' => $fresh->id,
                        'worker_engagement_id' => $actorContext['engagement']->id,
                    ],
                    workspaceId: $fresh->workspace_id,
                );
            }

            return $fresh;
        });

        if (! $didMutate) {
            return $fresh;
        }

        return $this->publishAndReturn($fresh, alreadyFresh: true);
    }

    public function disableAi(Conversation $conversation, User $actor): Conversation
    {
        $fresh = DB::transaction(function () use ($conversation, $actor): Conversation {
            $fresh = $conversation->fresh() ?? $conversation;
            $this->assertWorkspaceMember($fresh, $actor);

            if (! (bool) $fresh->ai_enabled) {
                return $fresh;
            }

            $fresh->update(['ai_enabled' => false]);

            return $this->refreshSummary($fresh->fresh() ?? $fresh);
        });

        return $this->publishAndReturn($fresh);
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

    public function currentStatus(Conversation $conversation): ConversationHandoffStatus
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

    private function createInternalHandoffNote(Conversation $conversation): Message
    {
        $content = 'تم تحويل المحادثة للفريق، وسيتم الرد عليك من أحد أعضاء الفريق.';

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'internal_note',
            'message_type' => 'system',
            'content' => $content,
            'ai_generated' => false,
            'metadata' => [
                'handoff_acknowledgement' => true,
                'visibility' => 'staff',
            ],
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        return $message;
    }

    private function assertOpen(Conversation $conversation): void
    {
        if ($conversation->isOpen()) {
            return;
        }

        throw new InvalidArgumentException('Handoff is only allowed on open conversations.');
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

    /**
     * @return array{
     *     workspace: Workspace,
     *     isMember: bool,
     *     isSupervisor: bool,
     *     engagement: ?WorkerEngagement,
     *     displayName: string,
     *     actorType: string
     * }
     */
    private function resolveHandoffActorContext(Conversation $conversation, User $actor): array
    {
        $workspace = Workspace::query()->find($conversation->workspace_id);
        if (! $workspace instanceof Workspace) {
            throw new InvalidArgumentException('Workspace not found.');
        }

        $membership = $workspace->users()
            ->where('users.id', $actor->id)
            ->wherePivot('status', 'active')
            ->first(['users.id', 'users.name']);

        if ($membership instanceof User) {
            $role = strtolower(trim((string) ($membership->pivot?->membership_role ?? '')));
            $isSupervisor = in_array($role, ['owner', 'admin', 'manager'], true)
                || $actor->can('workspace.manage')
                || $actor->can('conversations.manage')
                || (int) $workspace->owner_user_id === (int) $actor->id;

            return [
                'workspace' => $workspace,
                'isMember' => true,
                'isSupervisor' => $isSupervisor,
                'engagement' => null,
                'displayName' => $actor->name ?: 'موظف خدمة العملاء',
                'actorType' => 'user',
            ];
        }

        if ($conversation->status === 'archived') {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversation->id]);
        }

        $engagements = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('status', WorkerEngagementStatus::Active->value)
            ->whereHas('workerProfile', function (Builder $query) use ($actor): void {
                $query
                    ->where('user_id', $actor->id)
                    ->where('verification_status', 'approved')
                    ->where('lifecycle_status', 'verified');
            })
            ->with('workerProfile:id,user_id,display_name')
            ->orderBy('id')
            ->get();

        $readEngagement = $engagements->first(fn (WorkerEngagement $engagement): bool => $this->workerAuthorization->workerCan(
            $actor,
            $workspace,
            $engagement,
            WorkerInboxService::READ_CAPABILITY,
        ));

        if (! $readEngagement instanceof WorkerEngagement) {
            throw (new ModelNotFoundException)->setModel(Conversation::class, [$conversation->id]);
        }

        $sendEngagement = $engagements->first(fn (WorkerEngagement $engagement): bool => $this->workerAuthorization->workerCan(
            $actor,
            $workspace,
            $engagement,
            WorkerInboxService::READ_CAPABILITY,
        ) && $this->workerAuthorization->workerCan(
            $actor,
            $workspace,
            $engagement,
            WorkerInboxService::SEND_CAPABILITY,
        ));

        if (! $sendEngagement instanceof WorkerEngagement) {
            throw new AuthorizationException('لا تملك صلاحية تنفيذ هذا الإجراء.');
        }

        $displayName = $sendEngagement->workerProfile?->display_name
            ?: $actor->name
            ?: 'موظف مستقل';

        return [
            'workspace' => $workspace,
            'isMember' => false,
            'isSupervisor' => false,
            'engagement' => $sendEngagement,
            'displayName' => $displayName,
            'actorType' => 'worker',
        ];
    }

    private function resolveCurrentOwnerName(Conversation $conversation): ?string
    {
        if (is_string($conversation->responder_name) && $conversation->responder_name !== '') {
            return $conversation->responder_name;
        }

        if ($conversation->assigned_user_id !== null) {
            return $conversation->relationLoaded('assignedUser')
                ? $conversation->assignedUser?->name
                : $conversation->assignedUser()->value('name');
        }

        return null;
    }

    private function publishAndReturn(Conversation $conversation, bool $alreadyFresh = false): Conversation
    {
        $fresh = $alreadyFresh ? $conversation : ($conversation->fresh() ?? $conversation);
        $this->publishConversation($fresh, alreadyFresh: true);

        return $fresh;
    }

    private function publishConversation(Conversation $conversation, bool $alreadyFresh = false): void
    {
        app(InboxRealtimePublisher::class)->publishConversationUpdate($conversation, alreadyFresh: $alreadyFresh);
    }
}
