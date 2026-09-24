<?php

namespace App\Jobs;

use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Observability\OpsMetricsRecorder;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
        $incomingMessage = Message::withoutGlobalScopes()
            ->whereKey($this->messageId)
            ->where('conversation_id', $this->conversationId)
            ->first();

        if (! $conversation || ! $incomingMessage) {
            return;
        }

        if ((int) $incomingMessage->workspace_id !== (int) $conversation->workspace_id) {
            return;
        }

        app(WorkspaceContext::class)->runForId((int) $conversation->workspace_id, function () use (
            $aiService,
            $channelOutbound,
            $eligibility,
            $outputValidator,
            $conversation,
            $incomingMessage,
            $started,
        ): void {
            if (! $eligibility->mayGenerate($conversation->fresh() ?? $conversation, $incomingMessage)) {
                Log::info('ai.reply.skipped_precheck', [
                    'workspace_id' => $conversation->workspace_id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $incomingMessage->id,
                ]);

                return;
            }

            if ($this->existingReply($conversation, $incomingMessage)) {
                return;
            }

            $this->generateAndStoreReply(
                $aiService,
                $channelOutbound,
                $eligibility,
                $outputValidator,
                $conversation,
                $incomingMessage,
                $started,
            );
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ai.reply.job_failed', [
            'workspace_id' => $this->workspaceId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'reason' => $exception?->getMessage(),
        ]);

        app(OpsMetricsRecorder::class)->increment('ai.job_failed');
    }

    private function existingReply(Conversation $conversation, Message $incomingMessage): ?Message
    {
        return Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->where('metadata->in_reply_to_message_id', $incomingMessage->id)
            ->first();
    }

    private function generateAndStoreReply(
        AIService $aiService,
        ChannelOutboundService $channelOutbound,
        AiReplyEligibility $eligibility,
        AiOutputValidator $outputValidator,
        Conversation $conversation,
        Message $incomingMessage,
        float $startedAt,
    ): void {
        $conversationFresh = $conversation->fresh() ?? $conversation;
        if (! $eligibility->mayGenerate($conversationFresh, $incomingMessage)) {
            return;
        }

        try {
            $reply = $aiService->generateReply($conversationFresh, $incomingMessage);
        } catch (FeatureNotAvailableException|UsageLimitExceededException $exception) {
            Log::info('ai.reply.skipped_limits', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'message_id' => $incomingMessage->id,
                'reason' => $exception->getMessage(),
            ]);

            return;
        } catch (InvalidArgumentException $exception) {
            Log::warning('ai.reply.non_retryable', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'message_id' => $incomingMessage->id,
                'reason' => $exception->getMessage(),
            ]);
            $this->fail($exception);

            return;
        }

        $conversationFresh = $conversation->fresh() ?? $conversation;
        if (! $eligibility->mayPersist($conversationFresh, $incomingMessage)) {
            Log::info('ai.reply.discarded_takeover', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'inbound_message_id' => $incomingMessage->id,
            ]);

            return;
        }

        $validated = $outputValidator->validateForOutbound($reply);
        if ($validated === null) {
            Log::warning('ai.reply.output_rejected', [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'inbound_message_id' => $incomingMessage->id,
            ]);

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
                &$outboundMessage,
            ): void {
                if ($this->existingReply($conversationFresh, $incomingMessage)) {
                    return;
                }

                if (! $eligibility->mayPersist($conversationFresh->fresh() ?? $conversationFresh, $incomingMessage)) {
                    return;
                }

                $outboundMessage = Message::withoutGlobalScopes()->create([
                    'workspace_id' => $conversationFresh->workspace_id,
                    'conversation_id' => $conversationFresh->id,
                    'customer_id' => $conversationFresh->customer_id,
                    'direction' => 'outbound',
                    'message_type' => 'text',
                    'content' => $validated,
                    'ai_generated' => true,
                    'metadata' => [
                        'in_reply_to_message_id' => $incomingMessage->id,
                    ],
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if (! $outboundMessage instanceof Message) {
            return;
        }

        $conversationFresh->update(['last_message_at' => now()]);

        $channelOutbound->queueOutboundForMessage(
            $conversationFresh->fresh() ?? $conversationFresh,
            $outboundMessage,
        );

        ExtractInboxAgentMemoryJob::dispatch(
            (int) $conversationFresh->workspace_id,
            (int) $conversationFresh->id,
            (int) $incomingMessage->id,
        );

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('ai.reply.job_complete', [
            'workspace_id' => $conversationFresh->workspace_id,
            'conversation_id' => $conversationFresh->id,
            'inbound_message_id' => $incomingMessage->id,
            'outbound_message_id' => $outboundMessage->id,
            'duration_ms' => $durationMs,
        ]);

        app(OpsMetricsRecorder::class)->timing('ai.job_complete_ms', $durationMs);
        app(OpsMetricsRecorder::class)->increment('ai.job_complete');
    }
}
