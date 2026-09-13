<?php

namespace App\Support\Permissions;

use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

class WorkspaceTeamResolver implements PermissionsTeamResolver
{
    private int|string|null $workspaceId = null;

    public function getPermissionsTeamId(): int|string|null
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        return $this->workspaceId ?? $workspaceId;
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        $this->workspaceId = $id;
    }
}
