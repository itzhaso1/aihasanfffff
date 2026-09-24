<?php

namespace App\Services\Inbox;

use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Observability\OpsMetricsRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emits inbox realtime events for newly persisted messages only.
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

        try {
            event(new MessageCreated($message));
            if ($conversation) {
                event(new ConversationUpdated($conversation->fresh() ?? $conversation));
            }
        } catch (Throwable $exception) {
            Log::warning('inbox.realtime.broadcast_failed', $this->logContext($message, $conversation, $origin, $context, $started, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]));

            return;
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        Log::info('inbox.realtime.published', $this->logContext($message, $conversation, $origin, $context, $started, [
            'status' => 'ok',
        ]));
        app(OpsMetricsRecorder::class)->timing('realtime.publish_ms', $durationMs, [
            'origin' => $origin,
        ]);
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
        return array_merge([
            'workspace_id' => $message->workspace_id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->id,
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
