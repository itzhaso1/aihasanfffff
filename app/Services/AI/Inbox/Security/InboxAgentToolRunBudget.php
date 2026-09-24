<?php

namespace App\Services\AI\Inbox\Security;

final class InboxAgentToolRunBudget
{
    /** @var array<string, int> */
    private array $callsByTool = [];

    /** @var array<string, int> */
    private array $duplicateHashes = [];

    public function __construct(
        private readonly InboxAgentSecurityPolicy $policy,
    ) {}

    public function check(string $toolName, int $maxCallsPerRun, array $arguments): ?InboxAgentSecurityDecision
    {
        $this->callsByTool[$toolName] = ($this->callsByTool[$toolName] ?? 0) + 1;
        if ($this->callsByTool[$toolName] > $maxCallsPerRun) {
            return InboxAgentSecurityDecision::rateLimited('tool_call_limit');
        }

        $hash = hash('sha256', $toolName.':'.json_encode($arguments, JSON_THROW_ON_ERROR));
        $this->duplicateHashes[$hash] = ($this->duplicateHashes[$hash] ?? 0) + 1;
        if ($this->duplicateHashes[$hash] > $this->policy->maxDuplicateToolCallsPerRun()) {
            return InboxAgentSecurityDecision::rateLimited('duplicate_tool_call');
        }

        return null;
    }
}
