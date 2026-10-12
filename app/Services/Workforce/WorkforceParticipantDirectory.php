<?php

declare(strict_types=1);

namespace App\Services\Workforce;

use App\AI\Employees\Models\AiEmployee;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkforceParticipantType;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class WorkforceParticipantDirectory
{
    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @return Collection<int, WorkforceParticipant>
     */
    public function forWorkspace(User $actor, Workspace $workspace): Collection
    {
        $this->assertCanView($actor, $workspace);

        return $this->companyStaff($workspace)
            ->concat($this->independentWorkers($workspace))
            ->concat($this->aiEmployees($workspace))
            ->values();
    }

    public function resolve(
        User $actor,
        Workspace $workspace,
        WorkforceParticipantType $type,
        int $sourceId,
    ): ?WorkforceParticipant {
        $this->assertCanView($actor, $workspace);

        return match ($type) {
            WorkforceParticipantType::CompanyStaff => $this->companyStaffById($workspace, $sourceId),
            WorkforceParticipantType::IndependentWorker => $this->independentWorkerByEngagementId($workspace, $sourceId),
            WorkforceParticipantType::AiEmployee => $this->aiEmployeeById($workspace, $sourceId),
        };
    }

    /**
     * Independent workers are workspace-specific through an engagement.
     * Without a stored engagement id, resolution fails closed.
     */
    public function resolveIndependentWorker(
        User $actor,
        Workspace $workspace,
        ?int $engagementId,
    ): ?WorkforceParticipant {
        $this->assertCanView($actor, $workspace);

        if ($engagementId === null || $engagementId < 1) {
            return null;
        }

        return $this->independentWorkerByEngagementId($workspace, $engagementId);
    }

    /**
     * @return Collection<int, WorkforceParticipant>
     */
    private function companyStaff(Workspace $workspace): Collection
    {
        return $this->companyStaffQuery($workspace)
            ->orderBy('id')
            ->get()
            ->map(fn (WorkspaceUser $membership): WorkforceParticipant => $this->mapCompanyStaff($membership, $workspace));
    }

    private function companyStaffById(Workspace $workspace, int $sourceId): ?WorkforceParticipant
    {
        $membership = $this->companyStaffQuery($workspace)
            ->whereKey($sourceId)
            ->first();

        if ($membership === null) {
            return null;
        }

        return $this->mapCompanyStaff($membership, $workspace);
    }

    /**
     * @return Collection<int, WorkforceParticipant>
     */
    private function independentWorkers(Workspace $workspace): Collection
    {
        return $this->independentWorkerQuery($workspace)
            ->orderBy('id')
            ->get()
            ->map(fn (WorkerEngagement $engagement): ?WorkforceParticipant => $this->mapIndependentWorker($engagement))
            ->filter()
            ->values();
    }

    private function independentWorkerByEngagementId(Workspace $workspace, int $engagementId): ?WorkforceParticipant
    {
        $engagement = $this->independentWorkerQuery($workspace)
            ->whereKey($engagementId)
            ->first();

        if ($engagement === null) {
            return null;
        }

        return $this->mapIndependentWorker($engagement);
    }

    /**
     * @return Collection<int, WorkforceParticipant>
     */
    private function aiEmployees(Workspace $workspace): Collection
    {
        return $this->aiEmployeeQuery($workspace)
            ->orderBy('id')
            ->get()
            ->map(fn (AiEmployee $employee): WorkforceParticipant => $this->mapAiEmployee($employee, $workspace));
    }

    private function aiEmployeeById(Workspace $workspace, int $sourceId): ?WorkforceParticipant
    {
        $employee = $this->aiEmployeeQuery($workspace)
            ->whereKey($sourceId)
            ->first();

        if ($employee === null) {
            return null;
        }

        return $this->mapAiEmployee($employee, $workspace);
    }

    private function companyStaffQuery(Workspace $workspace): Builder
    {
        return WorkspaceUser::query()
            ->select([
                'id',
                'workspace_id',
                'user_id',
                'membership_role',
                'status',
            ])
            ->where('workspace_id', $workspace->id)
            ->with('user:id,name,avatar_path');
    }

    private function independentWorkerQuery(Workspace $workspace): Builder
    {
        return WorkerEngagement::queryForWorkspace((int) $workspace->id)
            ->select([
                'id',
                'workspace_id',
                'worker_profile_id',
                'status',
                'job_title',
            ])
            ->with('workerProfile:id,display_name,profile_photo');
    }

    private function aiEmployeeQuery(Workspace $workspace): Builder
    {
        return AiEmployee::queryForWorkspace((int) $workspace->id)
            ->select([
                'id',
                'workspace_id',
                'name',
                'avatar_path',
                'job_title',
                'role',
                'status',
            ]);
    }

    private function mapCompanyStaff(WorkspaceUser $membership, Workspace $workspace): WorkforceParticipant
    {
        return new WorkforceParticipant(
            type: WorkforceParticipantType::CompanyStaff,
            sourceId: (int) $membership->id,
            workspaceId: (int) $workspace->id,
            displayName: (string) ($membership->user?->name ?? 'موظف الشركة'),
            status: (string) $membership->status,
            role: $membership->membership_role,
            avatarPath: $membership->user?->avatar_path,
            workspaceUserId: (int) $membership->id,
        );
    }

    private function mapIndependentWorker(WorkerEngagement $engagement): ?WorkforceParticipant
    {
        if ($engagement->workerProfile === null) {
            return null;
        }

        $status = $engagement->status instanceof WorkerEngagementStatus
            ? $engagement->status->value
            : (string) $engagement->status;

        return new WorkforceParticipant(
            type: WorkforceParticipantType::IndependentWorker,
            sourceId: (int) $engagement->id,
            workspaceId: (int) $engagement->workspace_id,
            displayName: (string) $engagement->workerProfile->display_name,
            status: $status,
            role: $engagement->job_title,
            avatarPath: $engagement->workerProfile->profile_photo,
            workerProfileId: (int) $engagement->worker_profile_id,
            engagementId: (int) $engagement->id,
        );
    }

    private function mapAiEmployee(AiEmployee $employee, Workspace $workspace): WorkforceParticipant
    {
        return new WorkforceParticipant(
            type: WorkforceParticipantType::AiEmployee,
            sourceId: (int) $employee->id,
            workspaceId: (int) $workspace->id,
            displayName: (string) $employee->name,
            status: $employee->status->value,
            role: $employee->job_title ?: $employee->role,
            avatarPath: $employee->avatar_path,
            aiEmployeeId: (int) $employee->id,
        );
    }

    private function assertCanView(User $actor, Workspace $workspace): void
    {
        if (
            (int) $this->workspaceContext->workspaceId() !== (int) $workspace->id
            || ! $this->workspaceAccess->canManageMembers($actor, $workspace)
        ) {
            throw new AuthorizationException('You are not authorized to view workforce participants.');
        }
    }
}
