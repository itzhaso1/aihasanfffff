<?php

namespace App\Services\Inbox;

use App\Enums\ConversationHandoffStatus;
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
        bool $onlyIfJustCreated = false,
    ): bool {
        if ($onlyIfJustCreated && ! $message->wasRecentlyCreated) {
            return false;
        }

        if ($message->direction !== 'inbound' || trim((string) $message->content) === '') {
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

    /**
     * An assistant promise such as "أبشر، الفريق بيكمل معك هنا" is not a recorded review.
     */
    public function repairAssistantHandoffClaim(Conversation $conversation): bool
    {
        $fresh = $conversation->fresh() ?? $conversation;
        if ($fresh->handoffStatusEnum() !== ConversationHandoffStatus::None) {
            return false;
        }

        $claim = $this->unrecordedAssistantHandoffClaim($fresh);
        if (! $claim instanceof Message) {
            return false;
        }

        $inbound = Message::withoutGlobalScopes()
            ->where('workspace_id', $fresh->workspace_id)
            ->where('conversation_id', $fresh->id)
            ->where('direction', 'inbound')
            ->where('id', '<=', $claim->id)
            ->latest('id')
            ->first();

        if (! $inbound instanceof Message) {
            return false;
        }

        return $this->recordCustomerHandoff($fresh, $inbound);
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

        if (! $previous instanceof Message || ! $this->detector->claimsUnconfirmedHandoff($previous->content)) {
            return false;
        }

        return ! $this->handoffAcknowledgementExistsAfter($conversation, $previous);
    }

    private function unrecordedAssistantHandoffClaim(Conversation $conversation): ?Message
    {
        $latestAi = Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('ai_generated', true)
            ->latest('id')
            ->first();

        if (! $latestAi instanceof Message || ! $this->detector->claimsUnconfirmedHandoff($latestAi->content)) {
            return null;
        }

        if ($this->handoffAcknowledgementExistsAfter($conversation, $latestAi)) {
            return null;
        }

        return $latestAi;
    }

    private function handoffAcknowledgementExistsAfter(Conversation $conversation, Message $claim): bool
    {
        return Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('metadata->handoff_acknowledgement', true)
            ->where('id', '>=', $claim->id)
            ->exists();
    }

    /**
     * @deprecated Use ConversationHandoffService::resumeAi()
     */
    public function reEnableAi(Conversation $conversation): Conversation
    {
        throw new \BadMethodCallException('Use ConversationHandoffService::resumeAi() with an authorized staff user.');
    }
}
