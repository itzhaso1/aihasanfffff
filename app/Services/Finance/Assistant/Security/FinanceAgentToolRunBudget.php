<?php

namespace App\Services\Finance\Assistant\Security;

final class FinanceAgentToolRunBudget
{
    /** @var array<string, int> */
    private array $callsByTool = [];

    /** @var array<string, int> */
    private array $fingerprints = [];

    private readonly float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function check(string $toolName, int $maxCallsPerRun, array $arguments): ?FinanceAgentSecurityDecision
    {
        $budgetSeconds = max(5, (int) config('finance_assistant.security.max_request_seconds', 45));
        if ((microtime(true) - $this->startedAt) > $budgetSeconds) {
            return FinanceAgentSecurityDecision::rateLimited('request_time_budget', 'The request took too long.');
        }

        $this->callsByTool[$toolName] = ($this->callsByTool[$toolName] ?? 0) + 1;
        if ($this->callsByTool[$toolName] > $maxCallsPerRun) {
            return FinanceAgentSecurityDecision::rateLimited('tool_call_limit');
        }

        $hash = hash('sha256', $toolName.':'.json_encode($arguments));
        $this->fingerprints[$hash] = ($this->fingerprints[$hash] ?? 0) + 1;
        $maxDuplicates = max(1, (int) config('finance_assistant.security.max_duplicate_calls', 1));
        if ($this->fingerprints[$hash] > $maxDuplicates) {
            return FinanceAgentSecurityDecision::rateLimited('duplicate_tool_call');
        }

        return null;
    }
}
