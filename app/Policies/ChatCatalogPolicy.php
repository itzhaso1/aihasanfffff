<?php

namespace App\Policies;

use App\Models\ChatCatalog;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Chat\ChatCatalogAccess;
use App\Support\Tenancy\WorkspaceContext;

class ChatCatalogPolicy
{
    use ChecksWorkspaceMembership;

    public function view(User $user, ChatCatalog $catalog): bool
    {
        return $this->hasMembership($user, $catalog->workspace)
            && app(ChatCatalogAccess::class)->canView($user, $catalog->workspace);
    }

    public function update(User $user, ChatCatalog $catalog): bool
    {
        return $this->hasMembership($user, $catalog->workspace)
            && app(ChatCatalogAccess::class)->canManageSettings($user, $catalog->workspace);
    }

    public function viewAny(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null && app(ChatCatalogAccess::class)->canView($user, $workspace);
    }
}
