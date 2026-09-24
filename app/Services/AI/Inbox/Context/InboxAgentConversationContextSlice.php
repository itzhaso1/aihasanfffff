<?php

namespace App\Services\AI\Inbox\Context;

final class InboxAgentConversationContextSlice
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly string $channel,
        public readonly string $status,
        public readonly bool $aiEnabled,
        public readonly int $customerId,
        public readonly int $currentMessageId,
        public readonly ?string $currentMessageAt,
        public readonly array $metadata,
        public readonly ?string $summary,
    ) {}
}
