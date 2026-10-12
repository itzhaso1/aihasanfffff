<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Commerce\CommerceOrderAccess;
use App\Support\Tenancy\WorkspaceContext;

class OrderPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Order $order): bool
    {
        if ($order->source === 'commerce') {
            // Commerce orders have their own explicit policy abilities and
            // must not be exposed by legacy Orders/Payments endpoints.
            return false;
        }

        return $this->hasMembership($user, $order->workspace);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace || ! $this->hasMembership($user, $workspace)) {
            return false;
        }

        if ($user->can('orders.manage') || $user->can('workspace.manage')) {
            return true;
        }

        return $this->hasAnyWorkspaceRole($user, $workspace, ['owner', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Order $order): bool
    {
        if ($order->source === 'commerce') {
            return false;
        }

        return $this->hasAnyWorkspaceRole($user, $order->workspace, ['owner', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Order $order): bool
    {
        if ($order->source === 'commerce') {
            return false;
        }

        return $this->hasAnyWorkspaceRole($user, $order->workspace, ['owner', 'admin']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Order $order): bool
    {
        return $this->delete($user, $order);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Order $order): bool
    {
        return false;
    }

    public function viewCommerce(User $user, Order $order): bool
    {
        return $order->source === 'commerce'
            && app(CommerceOrderAccess::class)->canViewOrders($user, $order->workspace);
    }

    public function manageCommerce(User $user, Order $order): bool
    {
        return $order->source === 'commerce'
            && app(CommerceOrderAccess::class)->canManageOrders($user, $order->workspace);
    }

    public function cancelCommerce(User $user, Order $order): bool
    {
        return $order->source === 'commerce'
            && app(CommerceOrderAccess::class)->canCancelOrders($user, $order->workspace);
    }
}
