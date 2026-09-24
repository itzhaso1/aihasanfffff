<?php

namespace App\Services\Inbox;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

final class InboxHumanHandoffService
{
    public function __construct(
        private readonly InboxHumanHandoffDetector $detector,
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

        if (! $this->detector->requestsHumanAgent($message->content)) {
            return false;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        if (($metadata['human_handoff'] ?? false) === true && ! $conversation->ai_enabled) {
            return false;
        }

        $metadata['human_handoff'] = true;
        $metadata['human_handoff_at'] = now()->toIso8601String();
        $metadata['human_handoff_trigger_message_id'] = $message->id;
        $metadata['human_handoff_status'] = 'waiting_for_agent';

        $conversation->update([
            'ai_enabled' => false,
            'metadata' => $metadata,
        ]);

        Log::info('inbox.human_handoff.triggered', [
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => $conversation->channel,
        ]);

        return true;
    }

    public function reEnableAi(Conversation $conversation): Conversation
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        unset($metadata['human_handoff_status']);
        $metadata['human_handoff'] = false;
        $metadata['human_handoff_resumed_at'] = now()->toIso8601String();

        $conversation->update([
            'ai_enabled' => true,
            'metadata' => $metadata,
        ]);

        return $conversation->refresh();
    }
}
