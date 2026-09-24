<?php

namespace App\Services\Finance\Assistant\Security;

/**
 * Per-request trusted run state for the Finance agent.
 */
final class FinanceAgentRunState
{
    public ?FinanceAgentSecurityContext $security = null;

    public ?FinanceAgentToolRunBudget $budget = null;

    public function begin(FinanceAgentSecurityContext $security): void
    {
        $this->security = $security;
        $this->budget = new FinanceAgentToolRunBudget;
    }

    public function clear(): void
    {
        $this->security = null;
        $this->budget = null;
    }
}
