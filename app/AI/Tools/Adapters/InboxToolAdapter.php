<?php

declare(strict_types=1);

namespace App\AI\Tools\Adapters;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Tools\CanonicalTools;
use App\AI\Tools\Contracts\RuntimeTool;
use App\AI\Tools\RuntimeToolResult;
use App\AI\Tools\ToolDefinition;
use App\AI\Tools\ToolDenial;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentTool;
use Throwable;

final class InboxToolAdapter implements RuntimeTool
{
    public function __construct(
        private readonly InboxAgentTool $inner,
        private readonly ToolDefinition $definition,
        private readonly InboxToolContextFactory $contexts,
    ) {
        if ($inner->name() !== $definition->name) {
            throw new \InvalidArgumentException('Inbox adapter name must match the canonical definition.');
        }
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function execute(AiRequest $request, ResolvedEmployee $employee, array $arguments): RuntimeToolResult
    {
        unset($employee);

        if ($this->definition->writes && ($request->conversationId === null || $request->runMode->isSandboxed())) {
            return RuntimeToolResult::fail(
                $request->runMode->isSandboxed() ? ToolDenial::SANDBOX_WRITE : ToolDenial::CONVERSATION_REQUIRED,
                'This tool cannot run in the current mode.',
            );
        }

        try {
            $context = $this->contexts->make($request);
        } catch (Throwable) {
            return RuntimeToolResult::fail(ToolDenial::WORKSPACE_DENIED, 'Tool execution was denied.');
        }

        if ((int) $context->workspace->id !== $request->workspaceId) {
            return RuntimeToolResult::fail(ToolDenial::WORKSPACE_DENIED, 'Tool execution was denied.');
        }

        $arguments = $this->sandboxArguments($request, $arguments);

        try {
            $result = $this->inner->execute($context, $arguments);
        } catch (Throwable) {
            return RuntimeToolResult::fail(ToolDenial::EXECUTION_ERROR, 'Tool execution failed.');
        }

        if (! $result->success) {
            return RuntimeToolResult::fail(
                $result->errorCode ?? ToolDenial::EXECUTION_ERROR,
                (string) ($result->payload['message'] ?? 'Tool failed.'),
                $result->payload,
            );
        }

        return RuntimeToolResult::ok($result->payload);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function sandboxArguments(AiRequest $request, array $arguments): array
    {
        if ($request->runMode->isSandboxed() && $this->definition->name === CanonicalTools::EVALUATE_PRODUCT_SUITABILITY) {
            unset($arguments['customer_context']);
        }

        return $arguments;
    }
}
