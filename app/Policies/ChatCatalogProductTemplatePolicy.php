<?php

namespace App\Policies;

use App\Models\ChatCatalogProductTemplate;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Chat\ChatCatalogAccess;
use App\Support\Tenancy\WorkspaceContext;

class ChatCatalogProductTemplatePolicy
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

    public function view(User $user, ChatCatalogProductTemplate $template): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace || ! $template->isAccessibleByWorkspace((int) $workspace->id)) {
            return false;
        }

        return app(ChatCatalogAccess::class)->canView($user, $workspace);
    }

    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace) {
            return false;
        }

        return app(ChatCatalogAccess::class)->canManageItems($user, $workspace);
    }

    public function update(User $user, ChatCatalogProductTemplate $template): bool
    {
        if ($template->is_system || $template->workspace_id === null) {
            return false;
        }

        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace || (int) $template->workspace_id !== (int) $workspace->id) {
            return false;
        }

        return app(ChatCatalogAccess::class)->canManageItems($user, $workspace);
    }

    public function delete(User $user, ChatCatalogProductTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    public function clone(User $user, ChatCatalogProductTemplate $template): bool
    {
        return $this->create($user) && $template->is_system && $template->workspace_id === null;
    }
}
