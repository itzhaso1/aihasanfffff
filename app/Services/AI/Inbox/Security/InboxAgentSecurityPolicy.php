<?php

namespace App\Services\AI\Inbox\Security;

final class InboxAgentSecurityPolicy
{
    public function maxToolArgumentChars(): int
    {
        return max(32, (int) config('ai.inbox_agent.security.max_tool_argument_chars', 2000));
    }

    public function maxToolResultChars(): int
    {
        return max(200, (int) config('ai.inbox_agent.security.max_tool_result_chars', 8000));
    }

    public function maxDuplicateToolCallsPerRun(): int
    {
        return max(1, (int) config('ai.inbox_agent.security.max_duplicate_tool_calls', 2));
    }

    public function defaultMaxCallsPerTool(): int
    {
        return max(1, (int) config('ai.inbox_agent.security.default_max_calls_per_tool', 3));
    }
}
