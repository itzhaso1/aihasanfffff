<?php

namespace App\Services\AI\Inbox;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversation\ConversationAiEligibilityService;

/**
 * Race-safe guards for async inbox AI replies (human takeover + human priority).
 */
final class AiReplyEligibility
{
    public function __construct(
        private readonly ConversationAiEligibilityService $aiEligibility,
    ) {}

    public function mayGenerate(Conversation $conversation, Message $incomingMessage): bool
    {
        if (! $this->aiEligibility->mayRespond($conversation, $incomingMessage)) {
            return false;
        }

        return ! $this->humanOutboundSupersedesInbound($conversation, $incomingMessage);
    }

    public function mayPersist(Conversation $conversation, Message $incomingMessage): bool
    {
        $fresh = $conversation->fresh() ?? $conversation;

        return $this->mayGenerate($fresh, $incomingMessage);
    }

    public function humanOutboundSupersedesInbound(Conversation $conversation, Message $incomingMessage): bool
    {
        return Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('ai_generated', false)
            ->where(function ($query) use ($incomingMessage): void {
                $query->where('id', '>', $incomingMessage->id)
                    ->orWhere('created_at', '>', $incomingMessage->created_at);
            })
            ->exists();
    }
}
