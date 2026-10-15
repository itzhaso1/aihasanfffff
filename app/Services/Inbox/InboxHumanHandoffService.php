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

    public function maybeHandoffFromInboundMessage(Conversation $conversation, Message $message): bool
    {
        if (! $message->wasRecentlyCreated) {
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
            if ($this->detector->requestsHumanAgent($message->content)) {
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

    /**
     * @deprecated Use ConversationHandoffService::resumeAi()
     */
    public function reEnableAi(Conversation $conversation): Conversation
    {
        throw new \BadMethodCallException('Use ConversationHandoffService::resumeAi() with an authorized staff user.');
    }
}
