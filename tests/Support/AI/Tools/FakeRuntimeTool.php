<?php

declare(strict_types=1);

namespace Tests\Support\AI\Tools;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Tools\Contracts\RuntimeTool;
use App\AI\Tools\RuntimeToolResult;
use App\AI\Tools\ToolDefinition;

final class FakeRuntimeTool implements RuntimeTool
{
    /**
     * @param  (callable(AiRequest, ResolvedEmployee, array<string, mixed>): RuntimeToolResult)|null  $handler
     */
    public function __construct(
        private readonly ToolDefinition $definition,
        private readonly mixed $handler = null,
        private readonly RuntimeToolResult $result = new RuntimeToolResult(true, ['ok' => true]),
    ) {}

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function execute(AiRequest $request, ResolvedEmployee $employee, array $arguments): RuntimeToolResult
    {
        if (is_callable($this->handler)) {
            return ($this->handler)($request, $employee, $arguments);
        }

        return $this->result;
    }
}
