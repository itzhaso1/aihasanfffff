<?php

namespace App\Jobs;

use App\Exceptions\FeatureNotAvailableException;
use App\Exceptions\UsageLimitExceededException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\AIService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessAIResponse implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
    ) {}

    public function handle(AIService $aiService, WhatsAppOutboundService $whatsAppOutbound): void
    {
        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
        $incomingMessage = Message::withoutGlobalScopes()->find($this->messageId);

        if (! $conversation || ! $incomingMessage || ! $conversation->ai_enabled) {
            return;
        }

        app(WorkspaceContext::class)->runForId((int) $conversation->workspace_id, function () use ($aiService, $whatsAppOutbound, $conversation, $incomingMessage): void {
            $this->generateAndStoreReply($aiService, $whatsAppOutbound, $conversation, $incomingMessage);
        });
    }

    private function generateAndStoreReply(
        AIService $aiService,
        WhatsAppOutboundService $whatsAppOutbound,
        Conversation $conversation,
        Message $incomingMessage,
    ): void {

        try {
            $reply = $aiService->generateReply($conversation, $incomingMessage);
        } catch (FeatureNotAvailableException|UsageLimitExceededException $exception) {
            Log::info('AI reply skipped due to entitlement limits.', [
                'conversation_id' => $conversation->id,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        $outboundMessage = Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => $reply,
            'ai_generated' => true,
        ]);

        $conversation->update(['last_message_at' => now()]);

        $this->maybeDispatchWhatsAppOutbound($whatsAppOutbound, $conversation, $outboundMessage, $reply);
    }

    /**
     * Only sends when channel is whatsapp and both phone_number_id + recipient wa_id
     * are already known from inbound webhook metadata — never invents either value.
     */
    private function maybeDispatchWhatsAppOutbound(
        WhatsAppOutboundService $whatsAppOutbound,
        Conversation $conversation,
        Message $outboundMessage,
        string $reply,
    ): void {
        if ($conversation->channel !== 'whatsapp') {
            return;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;
        $recipientWaId = $conversation->external_id;

        if (! is_string($phoneNumberId) || $phoneNumberId === '') {
            Log::info('Skipping WhatsApp outbound: phone_number_id unknown on conversation.', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        if (! is_string($recipientWaId) || $recipientWaId === '' || $recipientWaId === 'unknown') {
            Log::info('Skipping WhatsApp outbound: recipient wa_id unknown on conversation.', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $workspace = Workspace::withoutGlobalScopes()->find($conversation->workspace_id);
        if (! $workspace) {
            return;
        }

        try {
            $whatsAppOutbound->sendText(
                workspace: $workspace,
                phoneNumberId: $phoneNumberId,
                to: $recipientWaId,
                body: $reply,
                conversationId: $conversation->id,
                messageId: $outboundMessage->id,
            );
        } catch (FeatureNotAvailableException|UsageLimitExceededException|\InvalidArgumentException $exception) {
            Log::warning('WhatsApp outbound not sent after AI reply.', [
                'conversation_id' => $conversation->id,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
