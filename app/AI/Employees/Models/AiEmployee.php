<?php

declare(strict_types=1);

namespace App\AI\Employees\Models;

use App\AI\Employees\EmployeeStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One AI employee of a workspace. The id is the identity; `name` is display data only
 * and must never drive behavior.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property EmployeeStatus $status
 * @property ?int $default_slot
 * @property int $config_version
 */
#[Fillable([
    'workspace_id',
    'name',
    'avatar_key',
    'avatar_path',
    'job_title',
    'gender',
    'role',
    'status',
    'instructions',
    'training_profile',
    'experience_notes',
    'preset_key',
    'preset_version',
    'legacy_ai_setting_id',
    'created_by',
    'updated_by',
])]
class AiEmployee extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    public const DEFAULT_SLOT = 1;

    protected $table = 'ai_employees';

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'default_slot' => 'integer',
            'preset_version' => 'integer',
            'config_version' => 'integer',
        ];
    }

    public function persona(): HasOne
    {
        return $this->hasOne(AiEmployeePersona::class, 'ai_employee_id');
    }

    public function skills(): HasMany
    {
        return $this->hasMany(AiEmployeeSkill::class, 'ai_employee_id')->orderBy('skill_key');
    }

    public function toolPermissions(): HasMany
    {
        return $this->hasMany(AiEmployeeToolPermission::class, 'ai_employee_id')->orderBy('tool_name');
    }

    public function memorySettings(): HasOne
    {
        return $this->hasOne(AiEmployeeMemorySettings::class, 'ai_employee_id');
    }

    public function modelSettings(): HasOne
    {
        return $this->hasOne(AiEmployeeModelSettings::class, 'ai_employee_id');
    }

    public function isDefault(): bool
    {
        return $this->default_slot === self::DEFAULT_SLOT;
    }

    public function isActive(): bool
    {
        return $this->status === EmployeeStatus::Active;
    }

    /**
     * @return list<string>
     */
    public function skillKeys(): array
    {
        return $this->skills->pluck('skill_key')->values()->all();
    }
}
