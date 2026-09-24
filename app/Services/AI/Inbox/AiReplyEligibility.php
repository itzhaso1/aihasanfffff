<?php

namespace App\Services\AI\Inbox;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Race-safe guards for async inbox AI replies (human takeover + human priority).
 */
final class AiReplyEligibility
{
    public function mayGenerate(Conversation $conversation, Message $incomingMessage): bool
    {
        if (! $this->conversationAiEnabled($conversation)) {
            return false;
        }

        if ((int) $incomingMessage->workspace_id !== (int) $conversation->workspace_id) {
            return false;
        }

        if ((int) $incomingMessage->conversation_id !== (int) $conversation->id) {
            return false;
        }

        if ($incomingMessage->direction !== 'inbound') {
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

    private function conversationAiEnabled(Conversation $conversation): bool
    {
        $fresh = $conversation->fresh();

        return (bool) ($fresh?->ai_enabled ?? $conversation->ai_enabled);
    }
}
