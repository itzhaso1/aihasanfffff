<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkerEngagementStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;

final class WorkerEngagementPolicy
{
    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null
            && $this->workspaceAccess->isActiveMember($user, $workspace);
    }

    public function view(User $user, WorkerEngagement $engagement): bool
    {
        return $this->workspaceAccess->isActiveMember($user, $engagement->workspace);
    }

    public function create(User $user): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null
            && $this->workspaceAccess->canManageMembers($user, $workspace);
    }

    public function manage(User $user, WorkerEngagement $engagement): bool
    {
        return $this->workspaceAccess->canManageMembers($user, $engagement->workspace);
    }

    public function manageGuide(User $user, WorkerEngagement $engagement): bool
    {
        return $this->workspaceAccess->canManageMembers($user, $engagement->workspace)
            && $engagement->status instanceof WorkerEngagementStatus
            && $engagement->status->allowsGuideManagement();
    }

    public function viewCapabilities(User $user, WorkerEngagement $engagement): bool
    {
        return $this->workspaceAccess->isActiveMember($user, $engagement->workspace);
    }

    public function viewSessions(User $user, WorkerEngagement $engagement): bool
    {
        return $this->workspaceAccess->isActiveMember($user, $engagement->workspace);
    }

    public function viewEarnings(User $user, WorkerEngagement $engagement): bool
    {
        return $this->workspaceAccess->isActiveMember($user, $engagement->workspace);
    }

    public function manageEarnings(User $user, WorkerEngagement $engagement): bool
    {
        return $this->workspaceAccess->canManageMembers($user, $engagement->workspace);
    }

    public function manageCapabilities(User $user, WorkerEngagement $engagement): bool
    {
        $workerProfile = $engagement->workerProfile;

        return $this->workspaceAccess->canManageMembers($user, $engagement->workspace)
            && $engagement->status instanceof WorkerEngagementStatus
            && $engagement->status->allowsCapabilityManagement()
            && $workerProfile !== null
            && $workerProfile->verification_status === WorkerProfile::VERIFICATION_APPROVED
            && $workerProfile->lifecycle_status === WorkerProfile::LIFECYCLE_VERIFIED;
    }
}
