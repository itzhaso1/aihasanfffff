<?php

namespace App\Services\AI\Inbox\Security;

use App\Enums\InboxAgentSecurityDecisionType;

final class InboxAgentSecurityDecision
{
    public function __construct(
        public readonly InboxAgentSecurityDecisionType $decision,
        public readonly string $reasonCode,
        public readonly string $publicMessage,
    ) {}

    public function allowed(): bool
    {
        return $this->decision === InboxAgentSecurityDecisionType::Allow;
    }

    public static function allow(): self
    {
        return new self(InboxAgentSecurityDecisionType::Allow, 'ok', '');
    }

    public static function deny(string $reasonCode, string $publicMessage): self
    {
        return new self(InboxAgentSecurityDecisionType::Deny, $reasonCode, $publicMessage);
    }

    public static function requiresConfirmation(string $reasonCode): self
    {
        return new self(
            InboxAgentSecurityDecisionType::RequiresConfirmation,
            $reasonCode,
            'This action requires explicit confirmation and is not available yet.',
        );
    }

    public static function rateLimited(string $reasonCode): self
    {
        return new self(
            InboxAgentSecurityDecisionType::RateLimited,
            $reasonCode,
            'Tool usage limit reached for this reply.',
        );
    }

    public static function invalid(string $reasonCode, string $publicMessage): self
    {
        return new self(InboxAgentSecurityDecisionType::Invalid, $reasonCode, $publicMessage);
    }
}
