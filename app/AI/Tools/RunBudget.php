<?php

declare(strict_types=1);

namespace App\AI\Tools;

final class RunBudget
{
    private const MAX_DUPLICATE_CALLS = 2;

    /** @var array<string, int> */
    private array $callsByTool = [];

    /** @var array<string, int> */
    private array $duplicateHashes = [];

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function consume(string $toolName, int $maxCallsPerRun, array $arguments): ?string
    {
        $this->callsByTool[$toolName] = ($this->callsByTool[$toolName] ?? 0) + 1;
        if ($this->callsByTool[$toolName] > $maxCallsPerRun) {
            return ToolDenial::CALL_LIMIT;
        }

        $hash = hash('sha256', $toolName.':'.json_encode($arguments, JSON_THROW_ON_ERROR));
        $this->duplicateHashes[$hash] = ($this->duplicateHashes[$hash] ?? 0) + 1;
        if ($this->duplicateHashes[$hash] > self::MAX_DUPLICATE_CALLS) {
            return ToolDenial::DUPLICATE_CALL;
        }

        return null;
    }
}
