<?php

declare(strict_types=1);

namespace App\AI\Tools\Contracts;

use App\AI\Core\AiRequest;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Tools\RuntimeToolResult;
use App\AI\Tools\ToolDefinition;

interface RuntimeTool
{
    public function definition(): ToolDefinition;

    /**
     * @param  array<string, mixed>  $arguments  Already authorized and schema-validated.
     */
    public function execute(AiRequest $request, ResolvedEmployee $employee, array $arguments): RuntimeToolResult;
}
