<?php

namespace App\Policies;

use App\Models\PosMenuItem;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class PosMenuItemPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PosMenuItem $item): bool
    {
        return $this->hasMembership($user, $item->workspace);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PosMenuItem $item): bool
    {
        return $this->hasAnyWorkspaceRole($user, $item->workspace, ['owner', 'admin', 'manager', 'agent', 'receptionist']);
    }

    public function delete(User $user, PosMenuItem $item): bool
    {
        return $this->hasAnyWorkspaceRole($user, $item->workspace, ['owner', 'admin', 'manager']);
    }
}
