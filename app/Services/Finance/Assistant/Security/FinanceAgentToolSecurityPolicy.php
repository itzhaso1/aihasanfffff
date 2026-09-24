<?php

namespace App\Services\Finance\Assistant\Security;

use App\Enums\Finance\FinanceAgentToolAccessMode;
use App\Enums\Finance\FinanceAgentToolRiskLevel;

final class FinanceAgentToolSecurityPolicy
{
    public function evaluate(FinanceAgentToolSecurityProfile $profile): FinanceAgentSecurityDecision
    {
        if ($profile->accessMode === FinanceAgentToolAccessMode::Write) {
            return FinanceAgentSecurityDecision::deny('write_tool_not_enabled', 'This action is not available.');
        }

        if ($profile->riskLevel === FinanceAgentToolRiskLevel::Critical
            || $profile->riskLevel === FinanceAgentToolRiskLevel::High) {
            return FinanceAgentSecurityDecision::deny('risk_denied', 'This action is not available.');
        }

        return FinanceAgentSecurityDecision::allow();
    }
}
