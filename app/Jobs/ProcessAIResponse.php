<?php

namespace App\Jobs;

use App\AI\Models\ModelGatewayException;
use App\Events\Realtime\TypingActivity;
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
use App\Services\Inbox\InboundMessageDeduplicationService;
use App\Services\Inbox\InboxHumanHandoffDetector;
use App\Services\Inbox\InboxHumanHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\Observability\OpsMetricsRecorder;
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
    ) {
        $this->onQueue((string) config('inbox.queues.ai', 'ai'));
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

                // 5. Generate and store reply
                $this->generateAndStoreReply(
                    $aiService,
                    $channelOutbound,
                    $eligibility,
                    $outputValidator,
                    $conversation,
                    $incomingMessage,
                    $generationId,
                    $started,
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
        return Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->where(function ($query) use ($incomingMessage): void {
                $query->where('external_message_id', 'ai_reply:'.$incomingMessage->id)
                    ->orWhere('metadata->in_reply_to_message_id', $incomingMessage->id);
            })
            ->first();
    }

    private function generateAndStoreReply(
        AIService $aiService,
        ChannelOutboundService $channelOutbound,
        AiReplyEligibility $eligibility,
        AiOutputValidator $outputValidator,
        Conversation $conversation,
        Message $incomingMessage,
        string $generationId,
        float $startedAt,
    ): void {
        $conversationFresh = $conversation->fresh() ?? $conversation;
        if (! $eligibility->mayGenerate($conversationFresh, $incomingMessage)) {
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
            event(new TypingActivity(
                workspaceId: (int) $conversation->workspace_id,
                conversationId: (int) $conversation->id,
                senderType: 'ai',
                senderName: 'مشعل AI',
                isTyping: true,
            ));
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
            );
        } finally {
            Cache::forget("conversation:{$conversation->id}:ai_generating");
            try {
                event(new TypingActivity(
                    workspaceId: (int) $conversation->workspace_id,
                    conversationId: (int) $conversation->id,
                    senderType: 'ai',
                    senderName: 'مشعل AI',
                    isTyping: false,
                ));
            } catch (Throwable) {
                // Best effort
            }
        }
    }

    private function performReplyGeneration(
        AIService $aiService,
        ChannelOutboundService $channelOutbound,
        AiReplyEligibility $eligibility,
        AiOutputValidator $outputValidator,
        Conversation $conversation,
        Message $incomingMessage,
        string $generationId,
        float $startedAt,
    ): void {
        $conversationFresh = $conversation->fresh() ?? $conversation;

        $fallbackReason = null;

        try {
            $reply = $aiService->generateReply($conversationFresh, $incomingMessage);
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
        if (! $eligibility->mayPersist($conversationFresh, $incomingMessage)) {
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

        try {
            DB::transaction(function () use (
                $conversationFresh,
                $incomingMessage,
                $eligibility,
                $validated,
                $generationId,
                &$outboundMessage,
            ): void {
                if ($this->existingReply($conversationFresh, $incomingMessage)) {
                    return;
                }

                if (! $eligibility->mayPersist($conversationFresh->fresh() ?? $conversationFresh, $incomingMessage)) {
                    return;
                }

                $stagedAttachments = app(AiMediaStagingService::class)->consumeStagedAttachments(
                    (int) $conversationFresh->workspace_id,
                    (int) $conversationFresh->id,
                );

                $outboundMessage = Message::withoutGlobalScopes()->create([
                    'workspace_id' => $conversationFresh->workspace_id,
                    'conversation_id' => $conversationFresh->id,
                    'customer_id' => $conversationFresh->customer_id,
                    'direction' => 'outbound',
                    'message_type' => ! empty($stagedAttachments) ? 'image' : 'text',
                    'content' => $validated,
                    'ai_generated' => true,
                    'external_message_id' => 'ai_reply:'.$incomingMessage->id,
                    'metadata' => [
                        'in_reply_to_message_id' => $incomingMessage->id,
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

        $conversationFresh->update(['last_message_at' => now()]);

        app(InboxRealtimePublisher::class)->publishNewMessage(
            $outboundMessage,
            $conversationFresh,
            [
                'provider' => 'ai_employee',
                'ai_generation_id' => $generationId,
                'inbound_message_id' => $incomingMessage->id,
            ],
        );

        $channelOutbound->queueOutboundForMessage(
            $conversationFresh->fresh() ?? $conversationFresh,
            $outboundMessage,
        );

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
}
