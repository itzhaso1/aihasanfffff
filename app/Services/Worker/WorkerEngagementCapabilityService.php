<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkforceCapabilityDefinition;
use App\Services\Audit\AuditLogService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkerEngagementCapabilityService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @return array{
     *     capabilities: Collection<int, WorkforceCapabilityDefinition>,
     *     permissions: Collection<string, WorkerEngagementPermission>
     * }
     */
    public function forCompany(WorkerEngagement $engagement): array
    {
        $this->assertCurrentWorkspace($engagement);

        return [
            'capabilities' => WorkforceCapabilityDefinition::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('capability_key')
                ->get(),
            'permissions' => $engagement->permissions()
                ->with('capabilityDefinition')
                ->get()
                ->keyBy('capability_key'),
        ];
    }

    public function grant(
        User $actor,
        WorkerEngagement $engagement,
        string $capabilityKey,
        ?Request $request = null,
    ): WorkerEngagementPermission {
        $this->assertCanManage($actor, $engagement);
        $capabilityKey = trim($capabilityKey);

        return DB::transaction(function () use (
            $actor,
            $engagement,
            $capabilityKey,
            $request,
        ): WorkerEngagementPermission {
            $lockedEngagement = $this->lockWorkspaceEngagement($engagement);
            $this->assertEngagementEligible($lockedEngagement);
            $definition = WorkforceCapabilityDefinition::query()
                ->where('capability_key', $capabilityKey)
                ->where('is_active', true)
                ->first();

            if ($definition === null) {
                throw WorkerEngagementCapabilityException::invalidCapability();
            }

            $permission = WorkerEngagementPermission::query()
                ->where('worker_engagement_id', $lockedEngagement->id)
                ->where('capability_key', $definition->capability_key)
                ->lockForUpdate()
                ->first();

            if ($permission?->allowed === true) {
                return $permission;
            }

            $oldAllowed = $permission?->allowed ?? false;
            $permission ??= new WorkerEngagementPermission([
                'worker_engagement_id' => $lockedEngagement->id,
                'capability_key' => $definition->capability_key,
            ]);
            $permission->forceFill(['allowed' => true])->save();

            $this->auditPermissionChange(
                'worker.engagement_capability.granted',
                $permission,
                $lockedEngagement,
                $actor,
                $request,
                $oldAllowed,
                true,
            );

            return $permission->refresh();
        });
    }

    public function revoke(
        User $actor,
        WorkerEngagement $engagement,
        string $capabilityKey,
        ?Request $request = null,
    ): ?WorkerEngagementPermission {
        $this->assertCanManage($actor, $engagement);
        $capabilityKey = trim($capabilityKey);

        return DB::transaction(function () use (
            $actor,
            $engagement,
            $capabilityKey,
            $request,
        ): ?WorkerEngagementPermission {
            $lockedEngagement = $this->lockWorkspaceEngagement($engagement);
            $this->assertEngagementEligible($lockedEngagement);
            $permission = WorkerEngagementPermission::query()
                ->where('worker_engagement_id', $lockedEngagement->id)
                ->where('capability_key', $capabilityKey)
                ->lockForUpdate()
                ->first();

            if ($permission === null || ! $permission->allowed) {
                return $permission;
            }

            $permission->forceFill(['allowed' => false])->save();

            $this->auditPermissionChange(
                'worker.engagement_capability.revoked',
                $permission,
                $lockedEngagement,
                $actor,
                $request,
                true,
                false,
            );

            return $permission->refresh();
        });
    }

    private function assertCanManage(User $actor, WorkerEngagement $engagement): void
    {
        $this->assertCurrentWorkspace($engagement);

        if (! $this->workspaceAccess->canManageMembers($actor, $engagement->workspace)) {
            throw new AuthorizationException('You are not authorized to manage engagement capabilities.');
        }

        $this->assertEngagementEligible($engagement);
    }

    private function assertEngagementEligible(WorkerEngagement $engagement): void
    {
        $workerProfile = $engagement->workerProfile;

        if (
            ! $engagement->status instanceof WorkerEngagementStatus
            || ! $engagement->status->allowsCapabilityManagement()
            || $workerProfile === null
            || $workerProfile->verification_status !== WorkerProfile::VERIFICATION_APPROVED
            || $workerProfile->lifecycle_status !== WorkerProfile::LIFECYCLE_VERIFIED
        ) {
            throw WorkerEngagementCapabilityException::engagementNotEligible();
        }
    }

    private function assertCurrentWorkspace(WorkerEngagement $engagement): void
    {
        if ((int) $this->workspaceContext->workspaceId() !== (int) $engagement->workspace_id) {
            throw WorkerEngagementCapabilityException::workspaceMismatch();
        }
    }

    private function lockWorkspaceEngagement(WorkerEngagement $engagement): WorkerEngagement
    {
        return WorkerEngagement::query()
            ->whereKey($engagement->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function auditPermissionChange(
        string $action,
        WorkerEngagementPermission $permission,
        WorkerEngagement $engagement,
        User $actor,
        ?Request $request,
        bool $oldAllowed,
        bool $newAllowed,
    ): void {
        $this->auditLog->log(
            action: $action,
            entityType: WorkerEngagementPermission::class,
            entityId: $permission->id,
            oldValues: ['allowed' => $oldAllowed],
            newValues: ['allowed' => $newAllowed],
            actor: $actor,
            request: $request,
            meta: [
                'worker_engagement_id' => $engagement->id,
                'capability_key' => $permission->capability_key,
            ],
            workspaceId: $engagement->workspace_id,
        );
    }
}
