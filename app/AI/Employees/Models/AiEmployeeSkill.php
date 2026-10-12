<?php

declare(strict_types=1);

namespace App\AI\Employees\Models;

use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['workspace_id', 'ai_employee_id', 'skill_key', 'settings'])]
class AiEmployeeSkill extends WorkspaceScopedModel
{
    protected $table = 'ai_employee_skills';

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
