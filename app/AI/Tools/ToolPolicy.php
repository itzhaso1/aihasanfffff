<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Skills\SkillRegistry;

final class ToolPolicy
{
    public function __construct(
        private readonly SkillRegistry $skills = new SkillRegistry,
    ) {}

    /**
     * @param  list<EmployeeToolGrant>  $grants
     */
    public function authorize(
        AiRequest $request,
        ResolvedEmployee $employee,
        ToolDefinition $definition,
        array $grants,
    ): ToolAuthorization {
        if (! $definition->enabled) {
            return ToolAuthorization::deny(ToolDenial::DISABLED);
        }

        if (in_array($definition->risk, [ToolRisk::High, ToolRisk::Critical], true)) {
            return ToolAuthorization::deny(ToolDenial::RISK_DENIED);
        }

        if ($definition->requiresConfirmation) {
            return ToolAuthorization::deny(ToolDenial::CONFIRMATION_REQUIRED);
        }

        if ($definition->writes && $request->runMode->isSandboxed()) {
            return ToolAuthorization::deny(ToolDenial::SANDBOX_WRITE);
        }

        if ($request->workspaceId <= 0) {
            return ToolAuthorization::deny(ToolDenial::WORKSPACE_DENIED);
        }

        $grant = $this->grantFor($definition->name, $grants);
        if ($grant !== null && ! $grant->allowed) {
            return ToolAuthorization::deny(ToolDenial::PERMISSION_DENIED);
        }

        $skillKeys = $this->enabledSkillKeys($employee->skillKeys);
        if ($skillKeys === [] && $grant === null) {
            return ToolAuthorization::deny(ToolDenial::PERMISSION_DENIED);
        }

        if ($skillKeys !== [] && ! $this->skillAllows($skillKeys, $definition->name)) {
            return ToolAuthorization::deny(ToolDenial::SKILL_DENIED);
        }

        if ($skillKeys !== [] && $definition->allowedSkillKeys !== [] && array_intersect($skillKeys, $definition->allowedSkillKeys) === []) {
            return ToolAuthorization::deny(ToolDenial::SKILL_DENIED);
        }

        if ($skillKeys === [] && ($grant === null || ! $grant->allowed)) {
            return ToolAuthorization::deny(ToolDenial::PERMISSION_DENIED);
        }

        $maxCalls = $definition->maxCallsPerRun;
        if ($grant?->maxCallsPerRun !== null) {
            $maxCalls = min($maxCalls, $grant->maxCallsPerRun);
        }

        return ToolAuthorization::allow($maxCalls);
    }

    /**
     * @param  list<ToolDefinition>  $definitions
     * @param  list<EmployeeToolGrant>  $grants
     * @return list<string>
     */
    public function offeredNames(
        AiRequest $request,
        ResolvedEmployee $employee,
        array $definitions,
        array $grants,
    ): array {
        $names = [];
        foreach ($definitions as $definition) {
            if ($this->authorize($request, $employee, $definition, $grants)->allowed) {
                $names[] = $definition->name;
            }
        }

        return $names;
    }

    /**
     * @param  list<EmployeeToolGrant>  $grants
     */
    private function grantFor(string $name, array $grants): ?EmployeeToolGrant
    {
        foreach ($grants as $grant) {
            if ($grant->name === $name) {
                return $grant;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $skillKeys
     * @return list<string>
     */
    private function enabledSkillKeys(array $skillKeys): array
    {
        $enabled = [];
        foreach ($skillKeys as $key) {
            $definition = $this->skills->definition($key, false);
            if ($definition !== null) {
                $enabled[] = $definition->key;
            }
        }

        return array_values(array_unique($enabled));
    }

    /**
     * @param  list<string>  $skillKeys
     */
    private function skillAllows(array $skillKeys, string $toolName): bool
    {
        foreach ($skillKeys as $key) {
            $definition = $this->skills->definition($key, false);
            if ($definition !== null && in_array($toolName, $definition->allowedTools, true)) {
                return true;
            }
        }

        return false;
    }
}
