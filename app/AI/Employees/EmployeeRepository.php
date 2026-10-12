<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\AI\Employees\Models\AiEmployee;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Workspace-scoped employee access. Every lookup takes the workspace explicitly, so an
 * employee id from another workspace never resolves, with or without an ambient context.
 */
final class EmployeeRepository
{
    private const RELATIONS = ['persona', 'skills', 'toolPermissions', 'memorySettings', 'modelSettings'];

    public function find(int $workspaceId, int $employeeId): ?AiEmployee
    {
        return $this->query($workspaceId)->whereKey($employeeId)->first();
    }

    public function findForWorkspace(Workspace $workspace, int $employeeId): ?AiEmployee
    {
        return $this->find((int) $workspace->id, $employeeId);
    }

    /** The active default employee, if the workspace has one. */
    public function findDefault(int $workspaceId): ?AiEmployee
    {
        return $this->query($workspaceId)
            ->where('default_slot', AiEmployee::DEFAULT_SLOT)
            ->where('status', EmployeeStatus::Active->value)
            ->first();
    }

    /**
     * @return Collection<int, AiEmployee> Default first, then by id.
     */
    public function listForWorkspace(int $workspaceId): Collection
    {
        return $this->query($workspaceId)
            ->orderByRaw('CASE WHEN default_slot IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->get();
    }

    public function exists(int $workspaceId, int $employeeId): bool
    {
        return AiEmployee::queryForWorkspace($workspaceId)->whereKey($employeeId)->exists();
    }

    public function hasDefault(int $workspaceId): bool
    {
        return AiEmployee::queryForWorkspace($workspaceId)->where('default_slot', AiEmployee::DEFAULT_SLOT)->exists();
    }

    public function save(AiEmployee $employee): AiEmployee
    {
        $employee->save();

        return $this->reload($employee);
    }

    public function reload(AiEmployee $employee): AiEmployee
    {
        return $this->find((int) $employee->workspace_id, (int) $employee->id)
            ?? throw new \LogicException('Employee disappeared during the operation.');
    }

    /**
     * @return Builder<AiEmployee>
     */
    private function query(int $workspaceId): Builder
    {
        return AiEmployee::queryForWorkspace($workspaceId)->with($this->scopedRelations());
    }

    /**
     * Child rows are reached through their parent employee, which is already scoped, so
     * relations work without an ambient workspace context (queued jobs, commands).
     *
     * @return array<string, \Closure>
     */
    private function scopedRelations(): array
    {
        $relations = [];
        foreach (self::RELATIONS as $relation) {
            $relations[$relation] = static fn ($query) => $query->withoutGlobalScope('workspace');
        }

        return $relations;
    }
}
