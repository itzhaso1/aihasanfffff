<?php

namespace App\Jobs;

use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  array<string, mixed>  $messageData
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly array $messageData,
        public readonly int $webhookEventId,
        public readonly ?string $phoneNumberId = null,
    ) {}

    public function handle(WhatsAppService $whatsAppService): void
    {
        $message = $whatsAppService->storeIncomingMessage(
            $this->workspaceId,
            $this->messageData,
            $this->phoneNumberId,
        );

        WebhookEvent::withoutGlobalScopes()
            ->whereKey($this->webhookEventId)
            ->where('workspace_id', $this->workspaceId)
            ->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);

        $conversation = Conversation::withoutGlobalScopes()
            ->where('workspace_id', $this->workspaceId)
            ->find($message->conversation_id);
        if ($conversation?->ai_enabled) {
            ProcessAIResponse::dispatch($conversation->id, $message->id);
        }
    }
}
