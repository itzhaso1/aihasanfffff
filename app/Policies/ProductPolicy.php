<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Tenancy\WorkspaceContext;

class ProductPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Product $product): bool
    {
        return $this->hasMembership($user, $product->workspace);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace) {
            return false;
        }

        if (! $this->hasMembership($user, $workspace)) {
            return false;
        }

        if ($user->can('products.manage') || $user->can('workspace.manage')) {
            return true;
        }

        return $this->hasAnyWorkspaceRole($user, $workspace, ['owner', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Product $product): bool
    {
        return $this->hasAnyWorkspaceRole($user, $product->workspace, ['owner', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Product $product): bool
    {
        return $this->hasAnyWorkspaceRole($user, $product->workspace, ['owner', 'admin']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Product $product): bool
    {
        return $this->delete($user, $product);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }
}
