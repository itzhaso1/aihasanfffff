<?php

namespace App\Services\Finance\Assistant\Security;

use App\Enums\Finance\FinanceAgentSecurityDecisionType;

final class FinanceAgentSecurityDecision
{
    private function __construct(
        public readonly FinanceAgentSecurityDecisionType $type,
        public readonly string $reasonCode,
        public readonly string $publicMessage = '',
    ) {}

    public static function allow(): self
    {
        return new self(FinanceAgentSecurityDecisionType::Allow, 'allow');
    }

    public static function deny(string $reasonCode, string $publicMessage = ''): self
    {
        return new self(FinanceAgentSecurityDecisionType::Deny, $reasonCode, $publicMessage);
    }

    public static function invalid(string $reasonCode, string $publicMessage = ''): self
    {
        return new self(FinanceAgentSecurityDecisionType::Invalid, $reasonCode, $publicMessage);
    }

    public static function rateLimited(string $reasonCode, string $publicMessage = ''): self
    {
        return new self(FinanceAgentSecurityDecisionType::RateLimited, $reasonCode, $publicMessage);
    }

    public function allowed(): bool
    {
        return $this->type === FinanceAgentSecurityDecisionType::Allow;
    }
}
