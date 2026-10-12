<?php

namespace App\Services\AI\Inbox\Security;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\Tools\InboxAgentToolSecurityProfile;

final class InboxAgentToolRiskPolicy
{
    public function evaluate(InboxAgentToolSecurityProfile $profile): InboxAgentSecurityDecision
    {
        if ($profile->requiresConfirmation) {
            return InboxAgentSecurityDecision::requiresConfirmation('confirmation_required');
        }

        if ($profile->riskLevel === InboxAgentToolRiskLevel::Critical) {
            return InboxAgentSecurityDecision::deny('risk_critical', 'This tool action is not permitted.');
        }

        if ($profile->riskLevel === InboxAgentToolRiskLevel::High) {
            return InboxAgentSecurityDecision::requiresConfirmation('risk_high');
        }

        if ($profile->accessMode === InboxAgentToolAccessMode::Write) {
            if ($profile->riskLevel !== InboxAgentToolRiskLevel::Read && $profile->riskLevel !== InboxAgentToolRiskLevel::Low) {
                return InboxAgentSecurityDecision::requiresConfirmation('write_tool_confirmation');
            }

            return InboxAgentSecurityDecision::requiresConfirmation('write_tool_not_enabled');
        }

        return InboxAgentSecurityDecision::allow();
    }
}
