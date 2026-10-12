<?php

namespace App\Services\AI\Inbox\Context;

final class InboxAgentContextFingerprint
{
    public function compute(InboxAgentStructuredContext $context): string
    {
        $payload = [
            'policy_version' => $context->metadata->policyVersion,
            'persona_fingerprint' => $context->persona->fingerprint,
            'workspace_id' => $context->workspace->workspaceId,
            'conversation_id' => $context->conversation->conversationId,
            'history_count' => count($context->messageWindow->history),
            'dropped_oldest' => $context->messageWindow->droppedOldestCount,
            'tools' => $context->tools->availableToolNames,
            'catalog_matched' => $context->catalog->matched,
            'catalog_communication' => $context->catalog->untrustedCommunication,
            'current_message_id' => $context->messageWindow->currentMessage->messageId,
            'memory_fingerprint' => $context->memory->retrievalFingerprint,
            'memory_enabled' => $context->memory->enabled,
        ];

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return 'ctx_v'.InboxAgentContextPolicy::VERSION.'_'.substr(hash('sha256', $encoded), 0, 16);
    }
}
