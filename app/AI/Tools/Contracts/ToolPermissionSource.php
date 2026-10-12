<?php

declare(strict_types=1);

namespace App\AI\Tools\Contracts;

use App\AI\Tools\EmployeeToolGrant;

interface ToolPermissionSource
{
    /**
     * Workspace-scoped permission rows for one employee. Empty means inherit from skills.
     *
     * @return list<EmployeeToolGrant>
     */
    public function grants(int $workspaceId, int $employeeId): array;
}
