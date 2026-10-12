<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\ToolRunner;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Data\ToolOutcome;
use App\AI\Core\Decision\ToolCallRecord;
use App\AI\Tools\Contracts\RuntimeTool;
use App\AI\Tools\Contracts\ToolPermissionSource;
use InvalidArgumentException;
use Throwable;
use WeakMap;

final class HasimToolRunner implements ToolRunner
{
    /** @var WeakMap<AiRequest, RunBudget> */
    private WeakMap $budgets;

    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolPolicy $policy,
        private readonly ToolPermissionSource $permissions,
        private readonly ArgumentSecurity $argumentSecurity = new ArgumentSecurity,
        private readonly ArgumentValidator $argumentValidator = new ArgumentValidator,
        private readonly ResultSanitizer $resultSanitizer = new ResultSanitizer,
        private readonly FactRecorder $factRecorder = new FactRecorder,
    ) {
        $this->budgets = new WeakMap;
    }

    public function availableTools(AiRequest $request, ResolvedEmployee $employee): array
    {
        $grants = $this->permissions->grants($request->workspaceId, $employee->employeeId);
        $names = $this->policy->offeredNames($request, $employee, $this->registry->definitions(false), $grants);

        return $this->registry->specsFor($names);
    }

    public function run(AiRequest $request, ResolvedEmployee $employee, ModelToolCall $call): ToolOutcome
    {
        if (! $this->registry->has($call->name)) {
            return $this->denied($call, ToolDenial::UNKNOWN);
        }

        $tool = $this->registry->get($call->name);
        $definition = $tool->definition();
        $grants = $this->permissions->grants($request->workspaceId, $employee->employeeId);
        $authorization = $this->policy->authorize($request, $employee, $definition, $grants);
        if (! $authorization->allowed) {
            return $this->denied($call, (string) $authorization->denial);
        }

        $argumentDenial = $this->argumentSecurity->inspect($call->arguments);
        if ($argumentDenial !== null) {
            return $this->denied($call, $argumentDenial);
        }

        try {
            $validated = $this->argumentValidator->validate($definition->inputSchema, $call->arguments);
        } catch (InvalidArgumentException) {
            return $this->denied($call, ToolDenial::INVALID_ARGUMENTS);
        }

        $budgetDenial = $this->budgetFor($request)->consume($call->name, $authorization->maxCallsPerRun, $validated);
        if ($budgetDenial !== null) {
            return $this->denied($call, $budgetDenial);
        }

        try {
            $result = $this->execute($tool, $request, $employee, $validated);
        } catch (Throwable) {
            return $this->denied($call, ToolDenial::EXECUTION_ERROR);
        }

        $payload = $this->resultSanitizer->sanitize($result->payload, $call->name, $result->success);
        if (! $result->success) {
            return new ToolOutcome(
                new ToolCallRecord($call->name, $call->callId, $call->arguments, false, $result->errorCode ?? ToolDenial::EXECUTION_ERROR),
                $payload,
            );
        }

        $facts = $result->facts !== []
            ? $result->facts
            : $this->factRecorder->facts($call->name, $validated, $payload);
        $action = $result->confirmedAction
            ?? $this->factRecorder->confirmedAction($call->name, $request, $payload);

        return new ToolOutcome(
            new ToolCallRecord($call->name, $call->callId, $call->arguments, true),
            $payload,
            $facts,
            $action,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function execute(RuntimeTool $tool, AiRequest $request, ResolvedEmployee $employee, array $arguments): RuntimeToolResult
    {
        return $tool->execute($request, $employee, $arguments);
    }

    private function budgetFor(AiRequest $request): RunBudget
    {
        return $this->budgets[$request] ??= new RunBudget;
    }

    private function denied(ModelToolCall $call, string $code): ToolOutcome
    {
        return new ToolOutcome(
            new ToolCallRecord($call->name, $call->callId, $call->arguments, false, $code),
            ['error' => $code, 'result_status' => $this->denialStatus($code)],
        );
    }

    private function denialStatus(string $code): string
    {
        return match ($code) {
            ToolDenial::PERMISSION_DENIED, ToolDenial::SKILL_DENIED, ToolDenial::DISABLED, ToolDenial::WORKSPACE_DENIED => 'unavailable',
            default => 'failed',
        };
    }
}
