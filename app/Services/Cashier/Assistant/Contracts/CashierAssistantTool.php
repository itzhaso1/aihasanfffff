<?php

namespace App\Services\Cashier\Assistant\Contracts;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;

interface CashierAssistantTool
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

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult;
}
