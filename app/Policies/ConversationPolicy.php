<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceMembership;
use App\Support\Tenancy\WorkspaceContext;

class ConversationPolicy
{
    use ChecksWorkspaceMembership;

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->hasMembership($user, $conversation->workspace);
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

        if ($user->can('conversations.manage') || $user->can('workspace.manage')) {
            return true;
        }

        return $this->hasAnyWorkspaceRole($user, $workspace, ['owner', 'admin', 'manager', 'agent']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $this->hasAnyWorkspaceRole($user, $conversation->workspace, ['owner', 'admin', 'manager', 'agent']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->hasAnyWorkspaceRole($user, $conversation->workspace, ['owner', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Conversation $conversation): bool
    {
        return $this->delete($user, $conversation);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Conversation $conversation): bool
    {
        return false;
    }
}
