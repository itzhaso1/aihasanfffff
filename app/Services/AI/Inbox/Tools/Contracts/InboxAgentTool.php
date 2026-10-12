<?php

namespace App\Services\AI\Inbox\Tools\Contracts;

use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutionResult;

/**
 * Explicit allowlisted Inbox agent tool. Implementations own business logic;
 * the registry/executor must never invoke arbitrary callables by name.
 */
interface InboxAgentTool
{
    public function name(): string;

    public function description(): string;

    /**
     * Provider-neutral input schema.
     *
     * @return array<string, array{type: string, required?: bool, description?: string}>
     */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $arguments  Already validated
     */
    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult;
}
