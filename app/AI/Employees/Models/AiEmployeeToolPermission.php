<?php

declare(strict_types=1);

namespace App\AI\Employees\Models;

use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['workspace_id', 'ai_employee_id', 'tool_name', 'allowed', 'max_calls_per_run'])]
class AiEmployeeToolPermission extends WorkspaceScopedModel
{
    protected $table = 'ai_employee_tool_permissions';

    protected function casts(): array
    {
        return ['allowed' => 'boolean', 'max_calls_per_run' => 'integer'];
    }
}
