<?php

namespace App\Policies\Commerce;

use App\Models\Commerce\CommerceCart;
use App\Models\User;
use App\Support\Commerce\CommerceOrderAccess;
use App\Support\Tenancy\WorkspaceContext;

final class CommerceCartPolicy
{
    public function viewAny(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null && app(CommerceOrderAccess::class)->canViewCarts($user, $workspace);
    }

    public function view(User $user, CommerceCart $cart): bool
    {
        return app(CommerceOrderAccess::class)->canViewCarts($user, $cart->workspace);
    }

    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null && app(CommerceOrderAccess::class)->canManageCarts($user, $workspace);
    }

    public function update(User $user, CommerceCart $cart): bool
    {
        return app(CommerceOrderAccess::class)->canManageCarts($user, $cart->workspace);
    }

    public function delete(User $user, CommerceCart $cart): bool
    {
        return $this->update($user, $cart);
    }
}
