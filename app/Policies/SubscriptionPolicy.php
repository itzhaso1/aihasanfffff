<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceMembership;

class SubscriptionPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Subscription $subscription): bool
    {
        return $this->hasMembership($user, $subscription->workspace);
    }

    /**
     * Direct create is closed. Billing mutations use manageBilling.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Checkout, payment confirmation, plan switch, and other billing writes.
     */
    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->hasAnyWorkspaceRole($user, $workspace, ['owner', 'admin']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Subscription $subscription): bool
    {
        return $this->manageBilling($user, $subscription->workspace);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Subscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Subscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Subscription $subscription): bool
    {
        return false;
    }
}
