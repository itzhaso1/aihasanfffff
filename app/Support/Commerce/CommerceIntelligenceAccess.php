<?php

namespace App\Support\Commerce;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Authorization\WorkspaceAccess;

final class CommerceIntelligenceAccess
{
    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function canViewIntelligence(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            && ($user->can('commerce.intelligence.view')
                || $user->can('commerce.intelligence.manage')
                || $this->workspaceAccess->isElevated($user, $workspace));
    }

    public function canManageIntelligence(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            && ($user->can('commerce.intelligence.manage')
                || $this->workspaceAccess->isElevated($user, $workspace));
    }

    public function canEvaluateIntelligence(User $user, Workspace $workspace): bool
    {
        return $this->isMember($user, $workspace)
            && ($user->can('commerce.intelligence.evaluate')
                || $this->canViewIntelligence($user, $workspace));
    }

    private function isMember(User $user, Workspace $workspace): bool
    {
        return $this->workspaceAccess->isActiveMember($user, $workspace)
            || $this->workspaceAccess->isWorkspaceOwner($user, $workspace);
    }
}
