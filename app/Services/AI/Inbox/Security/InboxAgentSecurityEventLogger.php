<?php

namespace App\Services\AI\Inbox\Security;

use Illuminate\Support\Facades\Log;

final class InboxAgentSecurityEventLogger
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function log(string $event, InboxAgentSecurityContext $securityContext, array $context = []): void
    {
        Log::info('ai.'.$event, array_merge([
            'workspace_id' => $securityContext->workspaceId,
            'conversation_id' => $securityContext->conversationId,
            'customer_id' => $securityContext->customerId,
            'correlation_id' => $securityContext->correlationId,
        ], $context));
    }
}
