<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Tools\Contracts\ToolPermissionSource;

final class StaticToolPermissionSource implements ToolPermissionSource
{
    /**
     * @param  array<int, list<EmployeeToolGrant>>  $grantsByEmployee
     */
    public function __construct(
        private readonly array $grantsByEmployee = [],
    ) {}

    public function grants(int $workspaceId, int $employeeId): array
    {
        unset($workspaceId);

        return $this->grantsByEmployee[$employeeId] ?? [];
    }
}
