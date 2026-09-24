<?php

namespace App\Policies\Commerce;

use App\Models\Commerce\SellerPaymentAccount;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Tenancy\WorkspaceContext;

class SellerPaymentAccountPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return $this->canManagePayments($user);
    }

    public function view(User $user, SellerPaymentAccount $account): bool
    {
        $workspace = $account->relationLoaded('workspace')
            ? $account->workspace
            : $account->workspace()->first();

        return $workspace && $this->hasMembership($user, $workspace);
    }

    public function create(User $user): bool
    {
        return $this->canManagePayments($user);
    }

    public function update(User $user, SellerPaymentAccount $account): bool
    {
        $workspace = $account->workspace()->first();
        if (! $workspace || ! $this->hasMembership($user, $workspace)) {
            return false;
        }

        return $this->hasAnyWorkspaceRole($user, $workspace, ['owner', 'admin']);
    }

    public function delete(User $user, SellerPaymentAccount $account): bool
    {
        return false;
    }

    private function canManagePayments(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace || ! $this->hasMembership($user, $workspace)) {
            return false;
        }

        if ($user->can('payments.manage') || $user->can('workspace.manage')) {
            return true;
        }

        return $this->hasAnyWorkspaceRole($user, $workspace, ['owner', 'admin']);
    }
}
