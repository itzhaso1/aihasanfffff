<?php

namespace App\Policies;

use App\Models\PosItemCategory;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class PosItemCategoryPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PosItemCategory $category): bool
    {
        return $this->hasMembership($user, $category->workspace);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PosItemCategory $category): bool
    {
        return $this->hasAnyWorkspaceRole($user, $category->workspace, ['owner', 'admin', 'manager', 'agent', 'receptionist']);
    }

    public function delete(User $user, PosItemCategory $category): bool
    {
        return $this->hasAnyWorkspaceRole($user, $category->workspace, ['owner', 'admin', 'manager']);
    }
}
