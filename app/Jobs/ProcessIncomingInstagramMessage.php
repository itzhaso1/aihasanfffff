<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\WebhookEvent;
use App\Services\Meta\Instagram\InstagramWebhookService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessIncomingInstagramMessage implements ShouldBeUnique, ShouldQueue
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
    ) {
        $this->onQueue((string) config('inbox.queues.inbound', 'inbound'));
    }

    public function uniqueId(): string
    {
        return 'instagram-inbound:'.$this->workspaceId.':'.$this->webhookEventId;
    }

    public function handle(InstagramWebhookService $instagramWebhookService): void
    {
        app(WorkspaceContext::class)->runForId($this->workspaceId, function () use ($instagramWebhookService): void {
            $message = $instagramWebhookService->storeIncomingMessage(
                $this->workspaceId,
                $this->messageData,
            );

            $conversation = Conversation::withoutGlobalScopes()
                ->where('workspace_id', $this->workspaceId)
                ->find($message->conversation_id);
            if ($conversation?->ai_enabled && $message->wasRecentlyCreated) {
                ProcessAIResponse::dispatch($conversation->id, $message->id, $this->workspaceId);
            }

            WebhookEvent::withoutGlobalScopes()
                ->whereKey($this->webhookEventId)
                ->where('workspace_id', $this->workspaceId)
                ->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);
        });
    }
}
