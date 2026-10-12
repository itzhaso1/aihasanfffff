<?php

namespace App\Policies;

use App\Models\ChatCatalogItem;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Chat\ChatCatalogAccess;
use App\Support\Tenancy\WorkspaceContext;

class ChatCatalogItemPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace) {
            return false;
        }

        return app(ChatCatalogAccess::class)->canView($user, $workspace);
    }

    public function view(User $user, ChatCatalogItem $item): bool
    {
        return $this->hasMembership($user, $item->workspace)
            && app(ChatCatalogAccess::class)->canView($user, $item->workspace);
    }

    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace) {
            return false;
        }

        return app(ChatCatalogAccess::class)->canManageItems($user, $workspace);
    }

    public function update(User $user, ChatCatalogItem $item): bool
    {
        return $this->hasMembership($user, $item->workspace)
            && app(ChatCatalogAccess::class)->canManageItems($user, $item->workspace);
    }

    public function delete(User $user, ChatCatalogItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function restore(User $user, ChatCatalogItem $item): bool
    {
        return $this->delete($user, $item);
    }

    public function forceDelete(User $user, ChatCatalogItem $item): bool
    {
        return false;
    }
}
