<?php

namespace Tests\Support;

use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentTool;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutionResult;
use RuntimeException;

final class InboxAgentArchitectureFailingTool implements InboxAgentTool
{
    public function name(): string
    {
        return 'architecture_fail';
    }

    public function description(): string
    {
        return 'Fails intentionally for architecture tests.';
    }

    public function inputSchema(): array
    {
        return [
            'trigger' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        throw new RuntimeException('secret-api-key-12345 should not reach model');
    }
}
