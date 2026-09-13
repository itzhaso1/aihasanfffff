<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Models\Workspace;

trait ChecksWorkspaceMembership
{
    protected function hasMembership(User $user, Workspace $workspace): bool
    {
        return $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    protected function hasAnyWorkspaceRole(User $user, Workspace $workspace, array $roles): bool
    {
        return $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('membership_role', $roles)
            ->exists();
    }
}
