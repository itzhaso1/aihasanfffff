<?php

namespace App\Services\Finance\Assistant\Contracts;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;

interface FinanceAssistantTool
{
    public function name(): string;

    public function description(): string;

    public function permission(): string;

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function validateArguments(array $arguments): array;

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult;
}
