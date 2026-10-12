<?php

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentTool;

final class InboxAgentToolSecurityProfile
{
    public function __construct(
        public readonly InboxAgentToolRiskLevel $riskLevel,
        public readonly InboxAgentToolAccessMode $accessMode,
        public readonly bool $requiresConfirmation,
        public readonly int $maxCallsPerRun,
    ) {}

    public static function forTool(InboxAgentTool $tool): self
    {
        if ($tool instanceof InboxAgentSecuredTool) {
            return $tool->securityProfile();
        }

        return new self(
            riskLevel: InboxAgentToolRiskLevel::Low,
            accessMode: InboxAgentToolAccessMode::Read,
            requiresConfirmation: false,
            maxCallsPerRun: 3,
        );
    }
}
