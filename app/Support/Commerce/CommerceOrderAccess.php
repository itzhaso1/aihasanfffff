<?php

namespace App\Support\Commerce;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Authorization\WorkspaceAccess;

final class CommerceOrderAccess
{
    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function canViewCarts(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            && ($user->can('commerce.cart.view')
                || $user->can('commerce.cart.manage')
                || $this->workspaceAccess->isElevated($user, $workspace));
    }

    public function canManageCarts(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            && ($user->can('commerce.cart.manage')
                || $this->workspaceAccess->isElevated($user, $workspace));
    }

    public function canViewOrders(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            && ($user->can('commerce.orders.view')
                || $user->can('commerce.orders.manage')
                || $this->workspaceAccess->isElevated($user, $workspace));
    }

    public function canManageOrders(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            && ($user->can('commerce.orders.manage')
                || $this->workspaceAccess->isElevated($user, $workspace));
    }

    public function canCancelOrders(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            && ($user->can('commerce.orders.cancel')
                || $this->canManageOrders($user, $workspace));
    }

    private function isMember(User $user, Workspace $workspace): bool
    {
        return $this->workspaceAccess->isActiveMember($user, $workspace)
            || $this->workspaceAccess->isWorkspaceOwner($user, $workspace);
    }
}
