<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Data\ToolOutcome;
use App\AI\Core\Data\ToolSpec;

interface ToolRunner
{
    /**
     * Tools this employee may use in this run.
     *
     * @return list<ToolSpec>
     */
    public function availableTools(AiRequest $request, ResolvedEmployee $employee): array;

    /**
     * Executes one offered tool call with authorization and validation. When the run mode
     * is sandboxed, implementations must not perform writes or external communication.
     */
    public function run(AiRequest $request, ResolvedEmployee $employee, ModelToolCall $call): ToolOutcome;
}
