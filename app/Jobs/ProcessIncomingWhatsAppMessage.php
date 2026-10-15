<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\WebhookEvent;
use App\Services\Conversation\ConversationAiEligibilityService;
use App\Services\Observability\OpsMetricsRecorder;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Observability\CorrelationContext;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessIncomingWhatsAppMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    /**
     * @param  array<string, mixed>  $messageData
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly array $messageData,
        public readonly int $webhookEventId,
        public readonly ?string $phoneNumberId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('inbox.queues.inbound', 'inbound'));
    }

    public function uniqueId(): string
    {
        return 'whatsapp-inbound:'.$this->workspaceId.':'.$this->webhookEventId;
    }

    public function handle(WhatsAppService $whatsAppService): void
    {
        $started = microtime(true);
        CorrelationContext::set($this->correlationId);

        try {
            app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($whatsAppService, $started): void {
                $message = $whatsAppService->storeIncomingMessage(
                    $this->workspaceId,
                    $this->messageData,
                    $this->phoneNumberId,
                );

                $conversation = Conversation::withoutGlobalScopes()
                    ->where('workspace_id', $this->workspaceId)
                    ->find($message->conversation_id);
                if (
                    $conversation
                    && $message->wasRecentlyCreated
                    && app(ConversationAiEligibilityService::class)->shouldDispatchAiJob($conversation, $message)
                ) {
                    ProcessAIResponse::dispatch($conversation->id, $message->id, $this->workspaceId);
                }

                WebhookEvent::withoutGlobalScopes()
                    ->whereKey($this->webhookEventId)
                    ->where('workspace_id', $this->workspaceId)
                    ->update([
                        'status' => 'processed',
                        'processed_at' => now(),
                    ]);

                $durationMs = (int) round((microtime(true) - $started) * 1000);

                Log::info('inbox.inbound.job_complete', array_merge(CorrelationContext::logContext(), [
                    'provider' => 'whatsapp',
                    'workspace_id' => $this->workspaceId,
                    'webhook_event_id' => $this->webhookEventId,
                    'message_id' => $message->id,
                    'conversation_id' => $message->conversation_id,
                    'was_new' => $message->wasRecentlyCreated,
                    'duration_ms' => $durationMs,
                    'queue' => 'inbound',
                ]));

                app(OpsMetricsRecorder::class)->timing('inbound.job_complete_ms', $durationMs, [
                    'provider' => 'whatsapp',
                ]);
                app(OpsMetricsRecorder::class)->increment('inbound.job_complete', [
                    'provider' => 'whatsapp',
                ]);
            });
        } finally {
            CorrelationContext::reset();
        }
    }
}
