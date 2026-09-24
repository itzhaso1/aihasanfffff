<?php

namespace App\Policies;

use App\Models\ChatCatalogCategory;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Chat\ChatCatalogAccess;
use App\Support\Tenancy\WorkspaceContext;

class ChatCatalogCategoryPolicy
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

    public function view(User $user, ChatCatalogCategory $category): bool
    {
        return $this->hasMembership($user, $category->workspace)
            && app(ChatCatalogAccess::class)->canView($user, $category->workspace);
    }

    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace) {
            return false;
        }

        return app(ChatCatalogAccess::class)->canManageCategories($user, $workspace);
    }

    public function update(User $user, ChatCatalogCategory $category): bool
    {
        return $this->hasMembership($user, $category->workspace)
            && app(ChatCatalogAccess::class)->canManageCategories($user, $category->workspace);
    }

    public function delete(User $user, ChatCatalogCategory $category): bool
    {
        return $this->update($user, $category);
    }
}
