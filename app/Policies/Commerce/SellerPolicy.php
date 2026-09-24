<?php

namespace App\Policies\Commerce;

use App\Models\Commerce\Seller;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Tenancy\WorkspaceContext;

class SellerPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return $this->canManageCommerce($user);
    }

    public function view(User $user, Seller $seller): bool
    {
        return $this->hasMembership($user, $seller->workspace);
    }

    public function create(User $user): bool
    {
        return $this->canManageCommerce($user);
    }

    public function update(User $user, Seller $seller): bool
    {
        if (! $this->hasMembership($user, $seller->workspace)) {
            return false;
        }

        return $this->hasAnyWorkspaceRole($user, $seller->workspace, ['owner', 'admin', 'manager']);
    }

    public function delete(User $user, Seller $seller): bool
    {
        if (! $this->hasMembership($user, $seller->workspace)) {
            return false;
        }

        return $this->hasAnyWorkspaceRole($user, $seller->workspace, ['owner', 'admin']);
    }

    private function canManageCommerce(User $user): bool
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
}
