<?php

namespace App\Services\AI\Inbox\Context;

/**
 * Provider-neutral conversation message (not tool-round provider messages).
 */
final class InboxAgentNormalizedMessage
{
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?int $messageId = null,
        public readonly ?string $occurredAt = null,
        public readonly ?string $channel = null,
    ) {}

    public function isCurrent(): bool
    {
        return $this->role === 'current_customer';
    }
}
