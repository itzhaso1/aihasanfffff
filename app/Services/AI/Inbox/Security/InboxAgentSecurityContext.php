<?php

namespace App\Services\AI\Inbox\Security;

use App\Services\AI\Inbox\InboxAgentContext;

/**
 * Trusted server-derived security context for an agent run.
 * Must never be populated from model output.
 */
final class InboxAgentSecurityContext
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $conversationId,
        public readonly int $customerId,
        public readonly string $channel,
        public readonly bool $aiEnabled,
        public readonly string $correlationId,
    ) {}

    public static function fromAgentContext(InboxAgentContext $context): self
    {
        return new self(
            workspaceId: (int) $context->workspace->id,
            conversationId: (int) $context->conversation->id,
            customerId: (int) $context->conversation->customer_id,
            channel: (string) $context->conversation->channel,
            aiEnabled: (bool) $context->conversation->ai_enabled,
            correlationId: $context->securityCorrelationId,
        );
    }
}
