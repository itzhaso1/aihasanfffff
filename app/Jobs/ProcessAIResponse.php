<?php

namespace App\Jobs;

use App\AI\Models\ModelGatewayException;
use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Workspace;
use App\Services\AI\AiMediaStagingService;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Inbox\InboxHonestReplyFallback;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationPresenceService;
use App\Services\Inbox\InboundMessageDeduplicationService;
use App\Services\Inbox\InboxHumanHandoffDetector;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Observability\OpsMetricsRecorder;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ProcessAIResponse implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly ?int $workspaceId = null,
        public readonly ?string $correlationId = null,
        public readonly int $coalesceAttempt = 0,
        public readonly ?int $expectedHandoffVersion = null,
    ) {
        $this->onQueue((string) config('inbox.queues.ai', 'ai'));
    }

    /**
     * Unified inbound dispatcher preserving correlation ID and optional async batching delay.
     */
    public static function dispatchForInbound(
        int $conversationId,
        int $messageId,
        ?int $workspaceId = null,
        ?string $correlationId = null,
        ?int $expectedHandoffVersion = null,
    ): void {
        $pending = self::dispatch(
            $conversationId,
            $messageId,
            $workspaceId,
            $correlationId ?? CorrelationContext::id(),
            0,
            $expectedHandoffVersion,
        );

        $batchSeconds = max(0, (int) config('ai.inbox_agent.batch_window_seconds', 0));
        if ($batchSeconds > 0 && config('queue.default') !== 'sync') {
            $pending->delay(now()->addSeconds($batchSeconds));
        }
    }

    public function uniqueId(): string
    {
        $workspace = $this->workspaceId ?? 0;

        return 'ai-reply:'.$workspace.':'.$this->conversationId.':'.$this->messageId;
    }

    public function handle(
        AIService $aiService,
        ChannelOutboundService $channelOutbound,
        AiReplyEligibility $eligibility,
        AiOutputValidator $outputValidator,
    ): void {
        $started = microtime(true);
        $generationId = (string) Str::uuid();
        CorrelationContext::set($this->correlationId);

        try {
            $this->runGeneration($aiService, $channelOutbound, $eligibility, $outputValidator, $started, $generationId);
        } finally {
            CorrelationContext::reset();
        }
    }

    private function runGeneration(
        AIService $aiService,
        ChannelOutboundService $channelOutbound,
        AiReplyEligibility $eligibility,
        AiOutputValidator $outputValidator,
        float $started,
        string $generationId,
    ): void {

        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
        $incomingMessage = Message::withoutGlobalScopes()
            ->whereKey($this->messageId)
            ->where('conversation_id', $this->conversationId)
            ->first();

        if (! $conversation || ! $incomingMessage) {
            Log::info('ai.reply.skipped_missing_entities', [
                'conversation_id' => $this->conversationId,
                'incoming_message_id' => $this->messageId,
                'workspace_id' => $this->workspaceId,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'reason' => 'conversation_or_message_not_found',
            ]);

            return;
        }

        if ((int) $incomingMessage->workspace_id !== (int) $conversation->workspace_id) {
            Log::warning('ai.reply.skipped_tenant_mismatch', [
                'conversation_id' => $this->conversationId,
                'incoming_message_id' => $this->messageId,
                'conversation_workspace_id' => $conversation->workspace_id,
                'message_workspace_id' => $incomingMessage->workspace_id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'reason' => 'workspace_tenant_mismatch',
            ]);

            return;
        }

        // Conversation-level lock to strictly serialize AI replies per conversation
        $conversationLockKey = "ai_reply_conv_lock:{$conversation->workspace_id}:{$conversation->id}";
        $lock = Cache::lock($conversationLockKey, 60);

        $acquired = false;
        try {
            $acquired = $lock->block(15);
        } catch (Throwable) {
            $acquired = $lock->get();
        }

        if (! $acquired) {
            Log::warning('ai.reply.skipped_concurrency_lock', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incomingMessage->id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'attempts' => $this->attempts(),
                'reason' => 'concurrency_lock_acquisition_failed',
            ]);

            return;
        }

        try {
            app(WorkspaceContext::class)->runForId((int) $conversation->workspace_id, function () use (
                $aiService,
                $channelOutbound,
                $eligibility,
                $outputValidator,
                $conversation,
                $incomingMessage,
                $generationId,
                $started,
            ): void {
                $fresh = $conversation->fresh() ?? $conversation;

                // 1. Human handoff check
                if (app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage($fresh, $incomingMessage, onlyIfJustCreated: false)) {
                    Log::info('ai.reply.skipped_handoff', [
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'incoming_message_id' => $incomingMessage->id,
                        'job_id' => $this->job?->getJobId(),
                        'ai_generation_id' => $generationId,
                        'attempts' => $this->attempts(),
                        'reason' => 'human_handoff_requested',
                    ]);

                    return;
                }
                $fresh = $fresh->fresh() ?? $fresh;

                // 2. Pre-check eligibility
                if (! $eligibility->mayGenerate($fresh, $incomingMessage)) {
                    Log::info('ai.reply.skipped_precheck', [
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'message_id' => $incomingMessage->id,
                        'inbound_message_id' => $incomingMessage->id,
                        'incoming_message_id' => $incomingMessage->id,
                        'job_id' => $this->job?->getJobId(),
                        'ai_generation_id' => $generationId,
                        'attempts' => $this->attempts(),
                        'reason' => $eligibility->skipReason($fresh, $incomingMessage),
                        'ai_enabled' => (bool) $fresh->ai_enabled,
                        'handoff_status' => $fresh->handoffStatusEnum()->value,
                    ]);

                    return;
                }

                // 3. Check if reply ALREADY exists for this incoming message (Idempotency & Queue Retry)
                $existingReply = $this->existingReply($conversation, $incomingMessage);
                if ($existingReply !== null) {
                    app(InboxHumanHandoffService::class)->repairAssistantHandoffClaim($fresh);

                    // Ensure outbound channel delivery is queued if it was missed
                    $channelOutbound->queueOutboundForMessage($fresh, $existingReply);

                    Log::info('ai.reply.skipped_already_replied', [
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'incoming_message_id' => $incomingMessage->id,
                        'outbound_message_id' => $existingReply->id,
                        'job_id' => $this->job?->getJobId(),
                        'ai_generation_id' => $generationId,
                        'attempts' => $this->attempts(),
                        'reason' => 'inbound_message_already_has_ai_reply',
                    ]);

                    return;
                }

                // 4. Check for rapid duplicate / webhook retry of recently answered identical question
                $dedup = app(InboundMessageDeduplicationService::class);
                $recentDuplicate = $dedup->findRecentAnsweredIdenticalInbound($fresh, $incomingMessage, windowSeconds: 30);
                if ($recentDuplicate !== null) {
                    Log::info('ai.reply.skipped_duplicate_inbound', [
                        'workspace_id' => $conversation->workspace_id,
                        'conversation_id' => $conversation->id,
                        'incoming_message_id' => $incomingMessage->id,
                        'previous_message_id' => $recentDuplicate['message']->id,
                        'previous_reply_id' => $recentDuplicate['reply']->id,
                        'job_id' => $this->job?->getJobId(),
                        'ai_generation_id' => $generationId,
                        'attempts' => $this->attempts(),
                        'reason' => 'identical_question_already_answered_recently',
                    ]);

                    return;
                }

                // 4.5. Coalesce rapid multi-message customer burst inside the conversation lock
                [$effectiveMessage, $targetMessage, $batchedIds] = $this->resolveRapidBurst(
                    $fresh,
                    $incomingMessage,
                    $eligibility,
                );
                if ($targetMessage->id !== $incomingMessage->id) {
                    if (app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage($fresh, $targetMessage, onlyIfJustCreated: false)) {
                        Log::info('ai.reply.skipped_handoff', [
                            'workspace_id' => $conversation->workspace_id,
                            'conversation_id' => $conversation->id,
                            'incoming_message_id' => $targetMessage->id,
                            'batched_inbound_ids' => $batchedIds,
                            'job_id' => $this->job?->getJobId(),
                            'ai_generation_id' => $generationId,
                            'reason' => 'human_handoff_requested_in_burst',
                        ]);

                        return;
                    }

                    $targetExisting = $this->existingReply($fresh, $targetMessage);
                    if ($targetExisting !== null) {
                        return;
                    }
                }

                // 5. Generate and store reply
                $this->generateAndStoreReply(
                    $aiService,
                    $channelOutbound,
                    $eligibility,
                    $outputValidator,
                    $conversation,
                    $targetMessage,
                    $generationId,
                    $started,
                    $effectiveMessage,
                    $batchedIds,
                );
            });
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ai.reply.job_failed', array_merge(
            $this->safeFailureContext(
                $exception,
                workspaceId: $this->workspaceId,
                conversationId: $this->conversationId,
                messageId: $this->messageId,
            ),
            [
                'job_id' => $this->job?->getJobId(),
                'attempts' => $this->attempts(),
            ]
        ));

        app(OpsMetricsRecorder::class)->increment('ai.job_failed');
    }

    public function existingReply(Conversation $conversation, Message $incomingMessage): ?Message
    {
        $direct = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->where(function ($query) use ($incomingMessage): void {
                $query->where('external_message_id', 'ai_reply:'.$incomingMessage->id)
                    ->orWhere('metadata->in_reply_to_message_id', $incomingMessage->id);
            })
            ->first();

        if ($direct !== null) {
            return $direct;
        }

        // Also treat messages covered inside a prior burst batch as already replied.
        $recentReplies = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('ai_generated', true)
            ->latest('id')
            ->limit(8)
            ->get();

        foreach ($recentReplies as $reply) {
            $batched = data_get($reply->metadata, 'batched_inbound_ids', []);
            if (is_array($batched) && in_array((int) $incomingMessage->id, array_map('intval', $batched), true)) {
                return $reply;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $batchedIds
     */
    private function generateAndStoreReply(
        AIService $aiService,
        ChannelOutboundService $channelOutbound,
        AiReplyEligibility $eligibility,
        AiOutputValidator $outputValidator,
        Conversation $conversation,
        Message $incomingMessage,
        string $generationId,
        float $startedAt,
        ?Message $effectiveMessage = null,
        array $batchedIds = [],
    ): void {
        $effectiveMessage ??= $incomingMessage;
        $batchedIds = $batchedIds !== [] ? $batchedIds : [(int) $incomingMessage->id];
        $conversationFresh = $conversation->fresh() ?? $conversation;
        $capturedHandoffVersion = $this->expectedHandoffVersion ?? (int) ($conversationFresh->handoff_version ?? 1);
        if ((int) ($conversationFresh->handoff_version ?? 1) !== $capturedHandoffVersion
            || ! $eligibility->mayGenerate($conversationFresh, $incomingMessage)) {
            Log::info('ai.reply.skipped_precheck', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incomingMessage->id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'attempts' => $this->attempts(),
                'reason' => $eligibility->skipReason($conversationFresh, $incomingMessage),
                'ai_enabled' => (bool) $conversationFresh->ai_enabled,
                'handoff_status' => $conversationFresh->handoffStatusEnum()->value,
            ]);

            return;
        }

        Cache::put("conversation:{$conversation->id}:ai_generating", true, 30);

        try {
            app(ConversationPresenceService::class)->setTyping(
                $conversation,
                'ai',
                true,
                senderType: 'ai',
                senderName: 'مشعل AI',
            );
        } catch (Throwable) {
            // Best effort
        }

        try {
            $this->performReplyGeneration(
                $aiService,
                $channelOutbound,
                $eligibility,
                $outputValidator,
                $conversation,
                $incomingMessage,
                $generationId,
                $startedAt,
                $effectiveMessage,
                $batchedIds,
                $capturedHandoffVersion,
            );
        } finally {
            Cache::forget("conversation:{$conversation->id}:ai_generating");
            try {
                app(ConversationPresenceService::class)->setTyping(
                    $conversation,
                    'ai',
                    false,
                    senderType: 'ai',
                    senderName: 'مشعل AI',
                );
            } catch (Throwable) {
                // Best effort
            }
        }
    }

    /**
     * @param  list<int>  $batchedIds
     */
    private function performReplyGeneration(
        AIService $aiService,
        ChannelOutboundService $channelOutbound,
        AiReplyEligibility $eligibility,
        AiOutputValidator $outputValidator,
        Conversation $conversation,
        Message $incomingMessage,
        string $generationId,
        float $startedAt,
        ?Message $effectiveMessage = null,
        array $batchedIds = [],
        ?int $capturedHandoffVersion = null,
    ): void {
        $effectiveMessage ??= $incomingMessage;
        $batchedIds = $batchedIds !== [] ? $batchedIds : [(int) $incomingMessage->id];
        $conversationFresh = $conversation->fresh() ?? $conversation;
        $capturedHandoffVersion ??= $this->expectedHandoffVersion ?? (int) ($conversationFresh->handoff_version ?? 1);
        $maxInboundIdBeforeGeneration = (int) (Message::withoutGlobalScopes()
            ->where('workspace_id', $conversationFresh->workspace_id)
            ->where('conversation_id', $conversationFresh->id)
            ->where('direction', 'inbound')
            ->max('id') ?? $incomingMessage->id);

        $fallbackReason = null;

        try {
            $reply = $aiService->generateReply($conversationFresh, $effectiveMessage);
        } catch (FeatureNotAvailableException $exception) {
            Log::info('ai.reply.skipped_limits', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incomingMessage->id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'feature' => $exception->feature,
            ]);

            return;
        } catch (UsageLimitExceededException $exception) {
            $payload = [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incomingMessage->id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'meter' => $exception->meter,
                'limit' => $exception->limit,
                'used' => $exception->used,
                'phase' => $exception->phase,
                'reason' => 'usage_limit_exceeded',
            ];

            if ($exception->phase === UsageLimitExceededException::PHASE_AFTER_GENERATION) {
                Log::warning('ai.reply.usage_accounting_failed', $payload);
            } else {
                Log::info('ai.reply.skipped_limits', $payload);
            }

            return;
        } catch (InvalidArgumentException $exception) {
            Log::warning('ai.reply.non_retryable', array_merge(
                $this->safeFailureContext(
                    $exception,
                    workspaceId: (int) $conversation->workspace_id,
                    conversationId: (int) $conversation->id,
                    messageId: (int) $incomingMessage->id,
                ),
                [
                    'job_id' => $this->job?->getJobId(),
                    'ai_generation_id' => $generationId,
                ]
            ));
            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            Log::warning('ai.reply.generation_exception', array_merge(
                $this->safeFailureContext(
                    $exception,
                    workspaceId: (int) $conversation->workspace_id,
                    conversationId: (int) $conversation->id,
                    messageId: (int) $incomingMessage->id,
                ),
                [
                    'job_id' => $this->job?->getJobId(),
                    'ai_generation_id' => $generationId,
                ]
            ));
            $reply = '';
            $fallbackReason = 'generation_exception';
        }

        $conversationFresh = $conversation->fresh() ?? $conversation;
        if ((int) ($conversationFresh->handoff_version ?? 1) !== $capturedHandoffVersion
            || ! $eligibility->mayPersist($conversationFresh, $incomingMessage)) {
            Log::info('ai.reply.discarded_takeover', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incomingMessage->id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'reason' => 'human_takeover_during_generation',
            ]);

            return;
        }

        // Discard stale reply if a brand-new inbound message arrived while generation was in flight
        if ($this->coalesceAttempt < 1) {
            $newerInbound = $this->newerInboundDuringGeneration($conversationFresh, $maxInboundIdBeforeGeneration);
            if ($newerInbound !== null && $eligibility->mayGenerate($conversationFresh, $newerInbound)) {
                Log::info('ai.reply.discarded_superseded_by_newer_inbound', array_merge(CorrelationContext::logContext(), [
                    'workspace_id' => $conversationFresh->workspace_id,
                    'conversation_id' => $conversationFresh->id,
                    'superseded_message_id' => $incomingMessage->id,
                    'newer_message_id' => $newerInbound->id,
                    'ai_generation_id' => $generationId,
                ]));
                app(OpsMetricsRecorder::class)->increment('ai.reply.superseded');

                if ($this->existingReply($conversationFresh, $newerInbound) === null) {
                    self::dispatch(
                        (int) $conversationFresh->id,
                        (int) $newerInbound->id,
                        (int) $conversationFresh->workspace_id,
                        CorrelationContext::id(),
                        coalesceAttempt: $this->coalesceAttempt + 1,
                    );
                }

                return;
            }
        }

        $validated = $outputValidator->validateForOutbound($reply);
        if ($validated === null) {
            $fallbackReason ??= trim((string) $reply) === '' ? 'generation_empty' : 'output_rejected';
            Log::warning('ai.reply.'.$fallbackReason, [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incomingMessage->id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'catalog_missing_is_not_a_skip_reason' => true,
            ]);

            $validated = $outputValidator->validateForOutbound(
                app(InboxHonestReplyFallback::class)->forInbound(
                    (string) $incomingMessage->content,
                    Workspace::query()->find((int) $conversationFresh->workspace_id),
                ),
            );
            if ($validated === null) {
                Log::warning('ai.reply.fallback_rejected', [
                    'workspace_id' => $conversation->workspace_id,
                    'conversation_id' => $conversation->id,
                    'incoming_message_id' => $incomingMessage->id,
                    'job_id' => $this->job?->getJobId(),
                    'ai_generation_id' => $generationId,
                ]);

                return;
            }

            Log::info('ai.reply.honest_fallback', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'incoming_message_id' => $incomingMessage->id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'reason' => $fallbackReason,
            ]);
        }

        if (app(InboxHumanHandoffDetector::class)->claimsUnconfirmedHandoff($validated)) {
            app(InboxHumanHandoffService::class)->recordCustomerHandoff($conversationFresh, $incomingMessage);

            return;
        }

        if ($this->existingReply($conversationFresh, $incomingMessage)) {
            return;
        }

        $outboundMessage = null;
        $outboundChannel = $channelOutbound->resolveOutboundChannel($conversationFresh);
        $initialDeliveryStatus = $channelOutbound->supportsChannel($outboundChannel) ? 'pending' : 'sent';

        try {
            DB::transaction(function () use (
                $conversationFresh,
                $incomingMessage,
                $eligibility,
                $capturedHandoffVersion,
                $validated,
                $generationId,
                $initialDeliveryStatus,
                $batchedIds,
                &$outboundMessage,
            ): void {
                $lockedConversation = Conversation::withoutGlobalScopes()
                    ->whereKey($conversationFresh->id)
                    ->lockForUpdate()
                    ->first() ?? $conversationFresh;

                if ($this->existingReply($lockedConversation, $incomingMessage)) {
                    return;
                }

                if ((int) ($lockedConversation->handoff_version ?? 1) !== $capturedHandoffVersion
                    || ! $eligibility->mayPersist($lockedConversation, $incomingMessage)) {
                    return;
                }

                $stagedAttachments = app(AiMediaStagingService::class)->consumeStagedAttachments(
                    (int) $lockedConversation->workspace_id,
                    (int) $lockedConversation->id,
                );

                $outboundMessage = Message::withoutGlobalScopes()->create([
                    'workspace_id' => $lockedConversation->workspace_id,
                    'conversation_id' => $lockedConversation->id,
                    'customer_id' => $lockedConversation->customer_id,
                    'direction' => 'outbound',
                    'message_type' => ! empty($stagedAttachments) ? 'image' : 'text',
                    'content' => $validated,
                    'ai_generated' => true,
                    'delivery_status' => $initialDeliveryStatus,
                    'external_message_id' => 'ai_reply:'.$incomingMessage->id,
                    'metadata' => [
                        'in_reply_to_message_id' => $incomingMessage->id,
                        'batched_inbound_ids' => $batchedIds,
                        'ai_generation_id' => $generationId,
                        'ai_employee_name' => 'مشعل',
                    ],
                ]);

                foreach ($stagedAttachments as $staged) {
                    MessageAttachment::withoutGlobalScopes()->create([
                        'workspace_id' => $outboundMessage->workspace_id,
                        'message_id' => $outboundMessage->id,
                        'provider' => 'chat_catalog',
                        'provider_media_id' => (string) Str::uuid(),
                        'disk' => $staged['disk'] ?? 'public',
                        'path' => $staged['path'],
                        'original_name' => $staged['original_name'] ?? basename((string) $staged['path']),
                        'mime_type' => $staged['mime_type'] ?? 'image/jpeg',
                        'size_bytes' => (int) ($staged['size_bytes'] ?? 0),
                        'kind' => 'image',
                        'status' => MessageAttachment::STATUS_STORED,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            $outboundMessage = $this->existingReply($conversationFresh, $incomingMessage);
            Log::info('ai.reply.dedup_unique_collision', [
                'workspace_id' => $conversationFresh->workspace_id,
                'conversation_id' => $conversationFresh->id,
                'incoming_message_id' => $incomingMessage->id,
                'outbound_message_id' => $outboundMessage?->id,
                'job_id' => $this->job?->getJobId(),
                'ai_generation_id' => $generationId,
                'reason' => 'unique_constraint_prevented_duplicate_ai_reply',
            ]);

            return;
        }

        if (! $outboundMessage instanceof Message) {
            return;
        }

        if (app(InboxHumanHandoffDetector::class)->claimsUnconfirmedHandoff($outboundMessage->content)) {
            app(InboxHumanHandoffService::class)->recordCustomerHandoff($conversationFresh, $incomingMessage);
        }

        $conversationFresh->update(['last_message_at' => $outboundMessage->created_at ?? now()]);
        $conversationFresh = $conversationFresh->fresh() ?? $conversationFresh;
        $outboundMessage->loadMissing(['attachments', 'user:id,name', 'customer:id,name']);

        app(InboxRealtimePublisher::class)->publishNewMessage(
            $outboundMessage,
            $conversationFresh,
            [
                'provider' => 'ai_employee',
                'ai_generation_id' => $generationId,
                'inbound_message_id' => $incomingMessage->id,
            ],
        );

        if ((int) ($conversationFresh->handoff_version ?? 1) === $capturedHandoffVersion
            && $eligibility->mayGenerate($conversationFresh, $incomingMessage)) {
            $channelOutbound->queueOutboundForMessage(
                $conversationFresh,
                $outboundMessage,
            );
        }

        try {
            ExtractInboxAgentMemoryJob::dispatch(
                (int) $conversationFresh->workspace_id,
                (int) $conversationFresh->id,
                (int) $incomingMessage->id,
            );
        } catch (Throwable) {
            // Memory extraction is non-blocking
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('ai.reply.job_complete', [
            'workspace_id' => $conversationFresh->workspace_id,
            'conversation_id' => $conversationFresh->id,
            'inbound_message_id' => $incomingMessage->id,
            'incoming_message_id' => $incomingMessage->id,
            'message_id' => $incomingMessage->id,
            'outbound_message_id' => $outboundMessage->id,
            'job_id' => $this->job?->getJobId(),
            'ai_generation_id' => $generationId,
            'attempts' => $this->attempts(),
            'duration_ms' => $durationMs,
        ]);

        app(OpsMetricsRecorder::class)->timing('ai.job_complete_ms', $durationMs);
        app(OpsMetricsRecorder::class)->increment('ai.job_complete');
    }

    /**
     * @return array{workspace_id: mixed, conversation_id: mixed, message_id: mixed, exception_type: ?string, provider_error_code?: string, http_status?: int}
     */
    private function safeFailureContext(
        ?Throwable $exception,
        mixed $workspaceId,
        mixed $conversationId,
        mixed $messageId,
    ): array {
        $context = [
            'workspace_id' => $workspaceId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'exception_type' => $exception !== null ? class_basename($exception::class) : null,
        ];

        if ($exception instanceof ModelGatewayException) {
            $context['provider_error_code'] = $exception->errorCode;
            if ($exception->httpStatus !== null) {
                $context['http_status'] = $exception->httpStatus;
            }
        }

        return $context;
    }

    /**
     * Collect contiguous unanswered inbound text messages sent back-to-back within the coalesce window.
     *
     * @return array{0: Message, 1: Message, 2: list<int>}
     */
    private function resolveRapidBurst(
        Conversation $conversation,
        Message $incomingMessage,
        AiReplyEligibility $eligibility,
    ): array {
        if ((int) config('ai.inbox_agent.batch_window_seconds', 0) <= 0) {
            return [$incomingMessage, $incomingMessage, [(int) $incomingMessage->id]];
        }

        $windowSeconds = max(1, (int) config('ai.inbox_agent.burst_coalesce_seconds', 30));
        $lastOutboundId = (int) (Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->max('id') ?? 0);

        $cutoff = ($incomingMessage->created_at ?? now())->copy()->subSeconds($windowSeconds);
        $burst = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('message_type', '!=', 'system')
            ->where('id', '>', $lastOutboundId)
            ->where('id', '>=', $incomingMessage->id)
            ->where('created_at', '>=', $cutoff)
            ->orderBy('id')
            ->limit(5)
            ->get();

        if ($burst->count() <= 1) {
            return [$incomingMessage, $incomingMessage, [(int) $incomingMessage->id]];
        }

        $eligibleBurst = $burst->filter(
            fn (Message $m): bool => trim((string) $m->content) !== '' && $eligibility->mayGenerate($conversation, $m),
        )->values();

        if ($eligibleBurst->count() <= 1) {
            return [$incomingMessage, $incomingMessage, [(int) $incomingMessage->id]];
        }

        /** @var Message $target */
        $target = $eligibleBurst->last();
        $ids = $eligibleBurst->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $dedup = app(InboundMessageDeduplicationService::class);
        $distinctLines = [];
        $seenNormalized = [];

        foreach ($eligibleBurst as $item) {
            $raw = trim((string) $item->content);
            $norm = $dedup->normalizeText($raw);
            if ($norm !== '' && isset($seenNormalized[$norm])) {
                continue;
            }
            $seenNormalized[$norm] = true;
            $distinctLines[] = $raw;
        }

        if (count($distinctLines) <= 1) {
            return [$target, $target, $ids];
        }

        $effective = $target->replicate();
        $effective->id = $target->id;
        $effective->exists = true;
        $effective->created_at = $target->created_at;
        $effective->content = implode("\n", $distinctLines);

        return [$effective, $target, $ids];
    }

    private function newerInboundDuringGeneration(Conversation $conversation, int $baselineInboundId): ?Message
    {
        return Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('message_type', '!=', 'system')
            ->where('id', '>', $baselineInboundId)
            ->orderByDesc('id')
            ->first();
    }
}
