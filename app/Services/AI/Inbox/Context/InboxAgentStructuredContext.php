<?php

namespace App\Services\AI\Inbox\Context;

/**
 * Structured, provider-neutral agent context assembled by the Context Engine.
 */
final class InboxAgentStructuredContext
{
    public function __construct(
        public readonly InboxAgentWorkspaceContextSlice $workspace,
        public readonly InboxAgentConversationContextSlice $conversation,
        public readonly InboxAgentCustomerContextSlice $customer,
        public readonly InboxAgentMessageWindow $messageWindow,
        public readonly InboxAgentPersonaContextSlice $persona,
        public readonly InboxAgentMemoryContextSlice $memory,
        public readonly InboxAgentBusinessContextSlice $business,
        public readonly InboxAgentToolContextSlice $tools,
        public readonly InboxAgentCatalogContextSlice $catalog,
        public readonly InboxAgentContextMetadata $metadata,
    ) {}
}
