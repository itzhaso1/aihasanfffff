<?php

namespace App\Services\AI\Inbox\Tools;

use App\Services\AI\AiSafety;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Security\InboxAgentSecurityContext;
use App\Services\AI\Inbox\Security\InboxAgentSecurityEventLogger;
use App\Services\AI\Inbox\Security\InboxAgentToolArgumentSecurity;
use App\Services\AI\Inbox\Security\InboxAgentToolResultSanitizer;
use App\Services\AI\Inbox\Security\InboxAgentToolRiskPolicy;
use App\Services\AI\Inbox\Security\InboxAgentToolRunBudget;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Security gateway and executor for allowlisted Inbox agent tools.
 */
final class InboxAgentToolExecutor
{
    public function __construct(
        private readonly InboxAgentToolRegistry $registry,
        private readonly InboxAgentToolArgumentValidator $argumentValidator,
        private readonly InboxAgentToolArgumentSecurity $argumentSecurity,
        private readonly InboxAgentToolRiskPolicy $riskPolicy,
        private readonly InboxAgentToolResultSanitizer $resultSanitizer,
        private readonly InboxAgentSecurityEventLogger $securityLogger,
        private readonly AiSafety $safety,
    ) {}

    /**
     * @param  list<InboxAgentToolCall>  $toolCalls
     * @return list<InboxAgentToolExecutionResult>
     */
    public function executeAll(
        InboxAgentContext $context,
        array $toolCalls,
        ?InboxAgentToolRunBudget $budget = null,
    ): array {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $results[] = $this->executeOne($context, $toolCall, $budget);
        }

        return $results;
    }

    public function executeOne(
        InboxAgentContext $context,
        InboxAgentToolCall $toolCall,
        ?InboxAgentToolRunBudget $budget = null,
    ): InboxAgentToolExecutionResult {
        $security = InboxAgentSecurityContext::fromAgentContext($context);
        $name = $toolCall->toolName;
        $callId = $toolCall->callId;

        try {
            $tool = $this->registry->get($name);
        } catch (InboxAgentToolRegistryException) {
            $this->securityLogger->log('tool.unknown', $security, ['tool' => $name]);

            return InboxAgentToolExecutionResult::failure(
                $name,
                $callId,
                'unknown_tool',
                'The requested tool is not available.',
            );
        }

        $profile = InboxAgentToolSecurityProfile::forTool($tool);
        $riskDecision = $this->riskPolicy->evaluate($profile);
        if (! $riskDecision->allowed()) {
            $this->securityLogger->log('tool.risk_denied', $security, [
                'tool' => $name,
                'reason' => $riskDecision->reasonCode,
            ]);

            return InboxAgentToolExecutionResult::failure(
                $name,
                $callId,
                $riskDecision->reasonCode,
                $riskDecision->publicMessage !== '' ? $riskDecision->publicMessage : 'Tool execution was denied.',
            );
        }

        $argumentDecision = $this->argumentSecurity->assertSafe($toolCall->arguments);
        if ($argumentDecision !== null) {
            $event = $argumentDecision->reasonCode === 'forbidden_identity_argument'
                ? 'tool.validation_failed'
                : 'tool.validation_failed';
            $this->securityLogger->log($event, $security, [
                'tool' => $name,
                'reason' => $argumentDecision->reasonCode,
            ]);

            return InboxAgentToolExecutionResult::failure(
                $name,
                $callId,
                $argumentDecision->reasonCode,
                $argumentDecision->publicMessage,
            );
        }

        if ($budget !== null) {
            $budgetDecision = $budget->check($name, $profile->maxCallsPerRun, $toolCall->arguments);
            if ($budgetDecision !== null) {
                $this->securityLogger->log('tool.loop_limited', $security, [
                    'tool' => $name,
                    'reason' => $budgetDecision->reasonCode,
                ]);

                return InboxAgentToolExecutionResult::failure(
                    $name,
                    $callId,
                    $budgetDecision->reasonCode,
                    $budgetDecision->publicMessage,
                );
            }
        }

        try {
            $validated = $this->argumentValidator->validate($tool->inputSchema(), $toolCall->arguments);
        } catch (\InvalidArgumentException $exception) {
            $this->securityLogger->log('tool.validation_failed', $security, [
                'tool' => $name,
                'reason' => 'schema_validation',
            ]);

            return InboxAgentToolExecutionResult::failure(
                $name,
                $callId,
                'invalid_arguments',
                $this->safeMessage($exception->getMessage()),
            );
        }

        if ((int) $context->workspace->id !== $security->workspaceId) {
            $this->securityLogger->log('tool.workspace_denied', $security, ['tool' => $name]);

            return InboxAgentToolExecutionResult::failure(
                $name,
                $callId,
                'workspace_denied',
                'Tool execution was denied.',
            );
        }

        try {
            $toolResult = $tool->execute($context, $validated);

            return $this->resultSanitizer->sanitize($toolResult, $name);
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.execution_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'tool' => $name,
                'call_id' => $callId,
                'correlation_id' => $security->correlationId,
                'error' => $this->safeMessage($exception->getMessage()),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $name,
                $callId,
                'execution_error',
                'Tool execution failed.',
            );
        }
    }

    private function safeMessage(string $message): string
    {
        return $this->safety->redactSecrets($message);
    }
}
