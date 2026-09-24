<?php

namespace App\Policies\Commerce;

use App\Models\User;
use App\Support\Commerce\CommerceIntelligenceAccess;
use App\Support\Tenancy\WorkspaceContext;

final class CommerceIntelligencePolicy
{
    public function __construct(
        private readonly CommerceIntelligenceAccess $access,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function viewAny(User $user): bool
    {
        $workspace = $this->workspaceContext->workspace();

        return $workspace ? $this->access->canViewIntelligence($user, $workspace) : false;
    }

    public function view(User $user): bool
    {
        $workspace = $this->workspaceContext->workspace();

        return $workspace ? $this->access->canViewIntelligence($user, $workspace) : false;
    }

    public function manage(User $user): bool
    {
        $workspace = $this->workspaceContext->workspace();

        return $workspace ? $this->access->canManageIntelligence($user, $workspace) : false;
    }

    public function evaluate(User $user): bool
    {
        $workspace = $this->workspaceContext->workspace();

        return $workspace ? $this->access->canEvaluateIntelligence($user, $workspace) : false;
    }
}
