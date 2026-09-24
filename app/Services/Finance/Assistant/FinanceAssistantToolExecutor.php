<?php

namespace App\Services\Finance\Assistant;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Security\FinanceAgentRunState;
use App\Services\Finance\Assistant\Security\FinanceAgentSecurityContext;
use App\Services\Finance\Assistant\Security\FinanceAgentSecurityEventLogger;
use App\Services\Finance\Assistant\Security\FinanceAgentToolArgumentSecurity;
use App\Services\Finance\Assistant\Security\FinanceAgentToolRunBudget;
use App\Services\Finance\Assistant\Security\FinanceAgentToolSecurityCatalog;
use App\Services\Finance\Assistant\Security\FinanceAgentToolSecurityPolicy;
use App\Support\Authorization\FinanceAccess;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Finance tool security gateway. Model output never establishes tenant or permission.
 */
final class FinanceAssistantToolExecutor
{
    public function __construct(
        private readonly FinanceAssistantToolRegistry $registry,
        private readonly FinanceAccess $financeAccess,
        private readonly FinanceAgentToolSecurityCatalog $securityCatalog,
        private readonly FinanceAgentToolSecurityPolicy $securityPolicy,
        private readonly FinanceAgentToolArgumentSecurity $argumentSecurity,
        private readonly FinanceAgentSecurityEventLogger $securityLogger,
        private readonly FinanceAgentRunState $runState,
    ) {}

    /**
     * @param  array{name: string, arguments?: array<string, mixed>}  $plan
     */
    public function execute(Workspace $workspace, User $user, array $plan): FinanceAssistantToolResult
    {
        $name = trim((string) ($plan['name'] ?? ''));
        $security = $this->security($workspace, $user);
        $budget = $this->runState->budget ?? new FinanceAgentToolRunBudget;

        if ($name === '') {
            $this->securityLogger->log('tool.unknown', $security, ['tool' => '']);

            return FinanceAssistantToolResult::unavailable('unknown', 'unknown_tool');
        }

        try {
            $tool = $this->registry->get($name);
        } catch (InvalidArgumentException) {
            $this->securityLogger->log('tool.unknown', $security, ['tool' => $name]);

            return FinanceAssistantToolResult::unavailable($name, 'unknown_tool');
        }

        $profile = $this->securityCatalog->profileFor($tool);
        $risk = $this->securityPolicy->evaluate($profile);
        if (! $risk->allowed()) {
            $this->securityLogger->log('tool.risk_denied', $security, [
                'tool' => $name,
                'reason' => $risk->reasonCode,
            ]);

            return FinanceAssistantToolResult::unavailable($name, $risk->reasonCode);
        }

        if ((int) $workspace->id !== $security->workspaceId || (int) $user->id !== $security->userId) {
            $this->securityLogger->log('tool.workspace_denied', $security, ['tool' => $name]);

            return FinanceAssistantToolResult::denied($name);
        }

        $sanitized = $this->argumentSecurity->sanitize(
            is_array($plan['arguments'] ?? null) ? $plan['arguments'] : []
        );
        if ($sanitized['decision'] !== null) {
            $this->securityLogger->log('tool.validation_failed', $security, [
                'tool' => $name,
                'reason' => $sanitized['decision']->reasonCode,
            ]);

            return FinanceAssistantToolResult::unavailable($name, $sanitized['decision']->reasonCode);
        }

        if ($sanitized['stripped'] !== []) {
            $this->securityLogger->log('tool.identity_stripped', $security, [
                'tool' => $name,
                'stripped_count' => count($sanitized['stripped']),
            ]);
        }

        $budgetDecision = $budget->check($name, $profile->maxCallsPerRun, $sanitized['arguments']);
        if ($budgetDecision !== null) {
            $this->securityLogger->log('tool.rate_limited', $security, [
                'tool' => $name,
                'reason' => $budgetDecision->reasonCode,
            ]);

            return FinanceAssistantToolResult::unavailable($name, $budgetDecision->reasonCode);
        }

        if (! $this->financeAccess->can($user, $workspace, $profile->permission)) {
            $this->securityLogger->log('tool.authorization_denied', $security, [
                'tool' => $name,
                'permission' => $profile->permission,
            ]);

            return FinanceAssistantToolResult::denied($name);
        }

        try {
            $validated = $tool->validateArguments($sanitized['arguments']);
        } catch (PeriodNotSupportedException $exception) {
            return FinanceAssistantToolResult::unavailable($name, 'period_not_supported', [
                'requested_period' => $exception->requestedPeriod,
            ]);
        } catch (ValidationException) {
            $this->securityLogger->log('tool.validation_failed', $security, [
                'tool' => $name,
                'reason' => 'invalid_arguments',
            ]);

            return FinanceAssistantToolResult::unavailable($name, 'invalid_arguments');
        }

        return $tool->execute($workspace, $user, $validated);
    }

    private function security(Workspace $workspace, User $user): FinanceAgentSecurityContext
    {
        if ($this->runState->security !== null
            && $this->runState->security->workspaceId === (int) $workspace->id
            && $this->runState->security->userId === (int) $user->id) {
            return $this->runState->security;
        }

        return FinanceAgentSecurityContext::fromActors($workspace, $user, null, 'fai_unscoped');
    }
}
