<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversation\ConversationHandoffService;

final class InboxHumanHandoffService
{
    public function __construct(
        private readonly InboxHumanHandoffDetector $detector,
        private readonly ConversationHandoffService $handoff,
    ) {}

    public function maybeHandoffFromInboundMessage(
        Conversation $conversation,
        Message $message,
        bool $onlyIfJustCreated = true,
    ): bool {
        if ($onlyIfJustCreated && ! $message->wasRecentlyCreated) {
            return false;
        }

        if ($message->direction !== 'inbound' || $message->message_type !== 'text') {
            return false;
        }

        if ((int) $message->conversation_id !== (int) $conversation->id) {
            return false;
        }

        $fresh = $conversation->fresh() ?? $conversation;

        try {
            if ($this->detector->requestsHumanAgent($message->content)
                || $this->previousAssistantClaimedHandoff($fresh, $message)) {
                return $this->handoff->requestFromCustomerPhrase($fresh, $message);
            }

            $reason = $this->detector->staffDecisionReason($message->content);
            if ($reason === null) {
                return false;
            }

            return $this->handoff->requestStaffReview($fresh, $message, $reason);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function recordCustomerHandoff(Conversation $conversation, Message $message): bool
    {
        if ($message->direction !== 'inbound' || (int) $message->conversation_id !== (int) $conversation->id) {
            return false;
        }

        try {
            return $this->handoff->requestFromCustomerPhrase($conversation->fresh() ?? $conversation, $message);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function previousAssistantClaimedHandoff(Conversation $conversation, Message $message): bool
    {
        $previous = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('ai_generated', true)
            ->where('id', '<', $message->id)
            ->latest('id')
            ->first();

        return $previous instanceof Message
            && $this->detector->claimsUnconfirmedHandoff($previous->content);
    }

    /**
     * @deprecated Use ConversationHandoffService::resumeAi()
     */
    public function reEnableAi(Conversation $conversation): Conversation
    {
        throw new \BadMethodCallException('Use ConversationHandoffService::resumeAi() with an authorized staff user.');
    }
}
