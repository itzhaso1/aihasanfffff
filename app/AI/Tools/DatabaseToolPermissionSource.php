<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Employees\Models\AiEmployeeToolPermission;
use App\AI\Tools\Contracts\ToolPermissionSource;

final class DatabaseToolPermissionSource implements ToolPermissionSource
{
    public function grants(int $workspaceId, int $employeeId): array
    {
        $rows = AiEmployeeToolPermission::query()
            ->withoutGlobalScope('workspace')
            ->where('workspace_id', $workspaceId)
            ->where('ai_employee_id', $employeeId)
            ->orderBy('tool_name')
            ->get();

        $grants = [];
        foreach ($rows as $row) {
            $grants[] = new EmployeeToolGrant(
                name: (string) $row->tool_name,
                allowed: (bool) $row->allowed,
                maxCallsPerRun: $row->max_calls_per_run !== null ? (int) $row->max_calls_per_run : null,
            );
        }

        return $grants;
    }
}
