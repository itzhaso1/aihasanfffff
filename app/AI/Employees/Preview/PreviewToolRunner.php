<?php

declare(strict_types=1);

namespace App\AI\Employees\Preview;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\ToolRunner;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Data\ToolOutcome;
use App\AI\Core\Decision\ToolCallRecord;

/**
 * Preview stays tool-free until the new Runtime is activated in Inbox.
 * Canonical tools are not offered or executed here.
 */
final class PreviewToolRunner implements ToolRunner
{
    public function availableTools(AiRequest $request, ResolvedEmployee $employee): array
    {
        return [];
    }

    public function run(AiRequest $request, ResolvedEmployee $employee, ModelToolCall $call): ToolOutcome
    {
        return new ToolOutcome(
            new ToolCallRecord($call->name, $call->callId, $call->arguments, false, ToolCallRecord::NOT_OFFERED),
            ['error' => ToolCallRecord::NOT_OFFERED],
        );
    }
}
