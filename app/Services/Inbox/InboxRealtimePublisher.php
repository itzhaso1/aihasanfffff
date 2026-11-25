<?php

namespace App\Services\Inbox;

use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Events\Realtime\MessageStatusUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Observability\OpsMetricsRecorder;
use App\Support\Observability\CorrelationContext;
use App\Support\Observability\OpsLogger;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emits inbox realtime events after database persistence completes.
 * Broadcast failures are logged and swallowed so DB persistence is never rolled back.
 */
final class InboxRealtimePublisher
{
    /**
     * @param  array<string, mixed>  $context  Optional latency/trace fields (provider, event_id, webhook_event_id, job_id)
     */
    public function publishNewInboundMessage(
        Message $message,
        ?Conversation $conversation = null,
        array $context = [],
    ): void {
        if (! $message->wasRecentlyCreated) {
            return;
        }

        $this->publish($message, $conversation, 'inbound', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function publishNewMessage(
        Message $message,
        ?Conversation $conversation = null,
        array $context = [],
    ): void {
        if (! $message->wasRecentlyCreated) {
            return;
        }

        $this->publish($message, $conversation, 'outbound', $context);
    }

    /**
     * Emit updated payload for an already-persisted message (e.g. async attachment ready).
     *
     * @param  array<string, mixed>  $context
     */
    public function publishMessageUpdated(
        Message $message,
        ?Conversation $conversation = null,
        array $context = [],
    ): void {
        $started = microtime(true);
        $conversation ??= Conversation::withoutGlobalScopes()->find($message->conversation_id);
        $freshConversation = $conversation?->fresh() ?? $conversation;

        try {
            event(new MessageCreated($message));
            if ($freshConversation) {
                event(new ConversationUpdated($freshConversation));
            }
        } catch (Throwable $exception) {
            Log::warning('inbox.realtime.message_update_failed', $this->logContext($message, $freshConversation, 'update', $context, $started, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]));

            return;
        }

        Log::info('inbox.realtime.message_updated', $this->logContext($message, $freshConversation, 'update', $context, $started, [
            'status' => 'ok',
        ]));
    }

    public function publishMessageStatus(
        Message $message,
        ?string $errorReason = null,
    ): void {
        $started = microtime(true);

        try {
            event(new MessageStatusUpdated($message, $errorReason));
        } catch (Throwable $exception) {
            Log::warning('inbox.realtime.status_publish_failed', array_merge(CorrelationContext::logContext(), [
                'workspace_id' => $message->workspace_id,
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'delivery_status' => $message->delivery_status,
                'error' => $exception->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]));

            return;
        }

        Log::info('inbox.realtime.status_published', array_merge(CorrelationContext::logContext(), [
            'workspace_id' => $message->workspace_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'delivery_status' => $message->delivery_status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]));
    }

    public function recordMessageDeliveryStatus(
        Message $message,
        string $status,
        ?string $errorReason = null,
        ?\DateTimeInterface $occurredAt = null,
        bool $allowRetryReset = false,
    ): void {
        $normalized = strtolower(trim($status));
        if (! in_array($normalized, ['pending', 'sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $rank = [
            'pending' => 0,
            'sending' => 0,
            'sent' => 1,
            'delivered' => 2,
            'read' => 3,
            'failed' => 4,
        ];

        $currentStatus = strtolower((string) ($message->delivery_status ?? ''));
        $currentRank = $rank[$currentStatus] ?? -1;
        $nextRank = $rank[$normalized];

        if ($allowRetryReset && $currentStatus === 'failed' && $normalized === 'pending') {
            // Explicit manual retry resets a failed outbound message back to pending.
        } else {
            if ($currentRank === 3 && $nextRank !== 3) {
                return;
            }
            if ($nextRank <= $currentRank && $normalized !== 'failed') {
                return;
            }
        }

        $at = $occurredAt ?? now();
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if ($normalized === 'failed') {
            if ($errorReason !== null && trim($errorReason) !== '') {
                $metadata['delivery_error'] = trim($errorReason);
            }
        } else {
            unset($metadata['delivery_error']);
        }

        $updates = [
            'delivery_status' => $normalized,
            'metadata' => $metadata,
        ];
        if ($normalized === 'delivered') {
            $updates['delivered_at'] = $message->delivered_at ?? $at;
        } elseif ($normalized === 'read') {
            $updates['delivered_at'] = $message->delivered_at ?? $at;
            $updates['read_at'] = $message->read_at ?? $at;
        }

        $message->forceFill($updates)->save();
        $this->publishMessageStatus($message->fresh() ?? $message, $errorReason);
    }

    public function publishConversationUpdate(Conversation $conversation): void
    {
        $started = microtime(true);

        try {
            event(new ConversationUpdated($conversation->fresh() ?? $conversation));
        } catch (Throwable $exception) {
            Log::warning('inbox.realtime.conversation_update_failed', array_merge(CorrelationContext::logContext(), [
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function publish(
        Message $message,
        ?Conversation $conversation,
        string $origin,
        array $context,
    ): void {
        $started = microtime(true);
        $conversation ??= Conversation::withoutGlobalScopes()->find($message->conversation_id);
        $freshConversation = $conversation?->fresh() ?? $conversation;

        try {
            event(new MessageCreated($message));
            if ($freshConversation) {
                event(new ConversationUpdated($freshConversation));
            }
        } catch (Throwable $exception) {
            Log::warning('inbox.realtime.broadcast_failed', $this->logContext($message, $freshConversation, $origin, $context, $started, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]));
            $this->dispatchCompanyPush($message, $freshConversation);

            return;
        }

        $this->dispatchCompanyPush($message, $freshConversation);

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        Log::info('inbox.realtime.published', $this->logContext($message, $freshConversation, $origin, $context, $started, [
            'status' => 'ok',
            'stage' => 'BROADCAST_DISPATCHED',
        ]));
        OpsLogger::info('BROADCAST_DISPATCHED', [
            'workspace_id' => $message->workspace_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'direction' => $message->direction,
            'delivery_status' => $message->delivery_status,
            'duration_ms' => $durationMs,
        ]);
        app(OpsMetricsRecorder::class)->timing('realtime.publish_ms', $durationMs, [
            'origin' => $origin,
        ]);
    }

    private function dispatchCompanyPush(Message $message, ?Conversation $conversation): void
    {
        try {
            app(CompanyInboxPushDispatcher::class)->dispatchForInbound($message, $conversation);
        } catch (Throwable) {
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(
        Message $message,
        ?Conversation $conversation,
        string $origin,
        array $context,
        float $startedAt,
        array $extra,
    ): array {
        return array_merge(CorrelationContext::logContext(), [
            'workspace_id' => $message->workspace_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
            'direction' => $message->direction,
            'ai_generated' => (bool) $message->ai_generated,
            'delivery_status' => $message->delivery_status,
            'origin' => $origin,
            'provider' => $context['provider'] ?? null,
            'event_id' => $context['event_id'] ?? null,
            'webhook_event_id' => $context['webhook_event_id'] ?? null,
            'job_id' => $context['job_id'] ?? null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'persisted_at' => optional($message->created_at)?->toIso8601String(),
        ], $extra);
    }
}
