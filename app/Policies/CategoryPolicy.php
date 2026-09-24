<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Tenancy\WorkspaceContext;

class CategoryPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Category $category): bool
    {
        return $this->hasMembership($user, $category->workspace);
    }

    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace || ! $this->hasMembership($user, $workspace)) {
            return false;
        }

        if ($user->can('products.manage') || $user->can('workspace.manage')) {
            return true;
        }

        return $this->hasAnyWorkspaceRole($user, $workspace, ['owner', 'admin', 'manager']);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->hasAnyWorkspaceRole($user, $category->workspace, ['owner', 'admin', 'manager']);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->hasAnyWorkspaceRole($user, $category->workspace, ['owner', 'admin']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Category $category): bool
    {
        return $this->delete($user, $category);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Category $category): bool
    {
        return false;
    }
}
