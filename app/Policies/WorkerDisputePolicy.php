<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Worker\WorkerDispute;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;

final class WorkerDisputePolicy
{
    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function viewAny(User $user): bool
    {
        $workspace = $this->workspaceContext->workspace();

        return $workspace !== null
            && $this->workspaceAccess->isActiveMember($user, $workspace);
    }

    public function view(User $user, WorkerDispute $dispute): bool
    {
        return (int) $this->workspaceContext->workspaceId() === (int) $dispute->workspace_id
            && $this->workspaceAccess->isActiveMember($user, $dispute->workspace);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, WorkerDispute $dispute): bool
    {
        return false;
    }

    public function delete(User $user, WorkerDispute $dispute): bool
    {
        return false;
    }
}
