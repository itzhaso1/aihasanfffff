<?php

namespace App\Support\Chat;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Authorization\WorkspaceAccess;

final class ChatCatalogAccess
{
    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function canView(User $user, Workspace $workspace): bool
    {
        if (! $this->hasCatalogMembership($user, $workspace)) {
            return false;
        }

        if ($user->can('chat.catalog.view')
            || $user->can('commerce.catalog.view')
            || $user->can('chat.catalog.manage')
            || $user->can('commerce.catalog.manage')
            || $user->can('commerce.catalog.items.manage')
            || $user->can('commerce.catalog.categories.manage')
            || $user->can('commerce.catalog.inventory.view')
            || $user->can('commerce.catalog.inventory.manage')
            || $user->can('workspace.manage')) {
            return true;
        }

        return $this->workspaceAccess->hasAnyMembershipRole($user, $workspace, [
            WorkspaceAccess::ROLE_OWNER,
            WorkspaceAccess::ROLE_ADMIN,
            WorkspaceAccess::ROLE_MANAGER,
        ]);
    }

    public function canManage(User $user, Workspace $workspace): bool
    {
        if (! $this->hasCatalogMembership($user, $workspace)) {
            return false;
        }

        if ($user->can('chat.catalog.manage')
            || $user->can('commerce.catalog.manage')
            || $user->can('workspace.manage')) {
            return true;
        }

        return $this->workspaceAccess->hasAnyMembershipRole($user, $workspace, [
            WorkspaceAccess::ROLE_OWNER,
            WorkspaceAccess::ROLE_ADMIN,
            WorkspaceAccess::ROLE_MANAGER,
        ]);
    }

    public function canManageSettings(User $user, Workspace $workspace): bool
    {
        if (! $this->hasCatalogMembership($user, $workspace)) {
            return false;
        }

        return $this->canManage($user, $workspace)
            || $user->can('commerce.catalog.settings.manage');
    }

    public function canManageItems(User $user, Workspace $workspace): bool
    {
        return $this->hasCatalogMembership($user, $workspace)
            && ($this->canManage($user, $workspace) || $user->can('commerce.catalog.items.manage'));
    }

    public function canManageCategories(User $user, Workspace $workspace): bool
    {
        return $this->hasCatalogMembership($user, $workspace)
            && ($this->canManage($user, $workspace) || $user->can('commerce.catalog.categories.manage'));
    }

    public function canManageInventory(User $user, Workspace $workspace): bool
    {
        return $this->hasCatalogMembership($user, $workspace)
            && ($this->canManage($user, $workspace) || $user->can('commerce.catalog.inventory.manage'));
    }

    public function canManageDigitalInventory(User $user, Workspace $workspace): bool
    {
        if (! $this->hasCatalogMembership($user, $workspace)) {
            return false;
        }

        return $this->canManage($user, $workspace)
            || $user->can('commerce.catalog.digital_inventory.manage');
    }

    public function canViewDigitalInventory(User $user, Workspace $workspace): bool
    {
        if (! $this->hasCatalogMembership($user, $workspace)) {
            return false;
        }

        return $this->canManageDigitalInventory($user, $workspace)
            || $user->can('commerce.catalog.digital_inventory.view');
    }

    private function hasCatalogMembership(User $user, Workspace $workspace): bool
    {
        return $this->workspaceAccess->isActiveMember($user, $workspace)
            || $this->workspaceAccess->isWorkspaceOwner($user, $workspace);
    }
}
