<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkforceCapabilityDefinition;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class WorkerEngagementAuthorization
{
    public const EMAIL_VIEW_CAPABILITY = 'email.view';

    public const STORIES_VIEW_CAPABILITY = 'stories.view';

    public function workerCan(
        User $worker,
        Workspace $workspace,
        WorkerEngagement|int $engagement,
        string $capabilityKey,
    ): bool {
        $capabilityKey = trim($capabilityKey);

        if ($capabilityKey === '') {
            return false;
        }

        $engagementId = $engagement instanceof WorkerEngagement
            ? $engagement->getKey()
            : $engagement;

        $ownedActiveEngagement = WorkerEngagement::withoutGlobalScopes()
            ->whereKey($engagementId)
            ->where('workspace_id', $workspace->id)
            ->where('status', WorkerEngagementStatus::Active->value)
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query
                    ->where('user_id', $worker->id)
                    ->where('verification_status', WorkerProfile::VERIFICATION_APPROVED)
                    ->where('lifecycle_status', WorkerProfile::LIFECYCLE_VERIFIED);
            })
            ->first();

        if ($ownedActiveEngagement === null) {
            return false;
        }

        return WorkforceCapabilityDefinition::query()
            ->where('capability_key', $capabilityKey)
            ->where('is_active', true)
            ->whereHas('permissions', function (Builder $query) use ($ownedActiveEngagement): void {
                $query
                    ->where('worker_engagement_id', $ownedActiveEngagement->id)
                    ->where('allowed', true);
            })
            ->exists();
    }

    public function workerCanInWorkspace(
        User $worker,
        Workspace $workspace,
        string $capabilityKey,
    ): bool {
        $capabilityKey = trim($capabilityKey);

        if ($capabilityKey === '') {
            return false;
        }

        return WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('status', WorkerEngagementStatus::Active->value)
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query
                    ->where('user_id', $worker->id)
                    ->where('verification_status', WorkerProfile::VERIFICATION_APPROVED)
                    ->where('lifecycle_status', WorkerProfile::LIFECYCLE_VERIFIED);
            })
            ->whereHas('permissions', function (Builder $query) use ($capabilityKey): void {
                $query
                    ->where('capability_key', $capabilityKey)
                    ->where('allowed', true)
                    ->whereHas('capabilityDefinition', function (Builder $definition): void {
                        $definition->where('is_active', true);
                    });
            })
            ->exists();
    }

    public function authorize(
        User $worker,
        Workspace $workspace,
        WorkerEngagement|int $engagement,
        string $capabilityKey,
    ): void {
        if (! $this->workerCan($worker, $workspace, $engagement, $capabilityKey)) {
            throw new AuthorizationException('The worker is not authorized for this capability.');
        }
    }

    public function authorizeAssignedProducts(
        User $worker,
        Workspace $workspace,
        WorkerEngagement|int $engagement,
    ): void {
        if (! $this->workerCanAssignedProducts($worker, $workspace, $engagement)) {
            throw new AuthorizationException('The worker is not authorized to view assigned products.');
        }
    }

    public function workerCanAssignedProducts(
        User $worker,
        Workspace $workspace,
        WorkerEngagement|int $engagement,
    ): bool {
        $engagementId = $engagement instanceof WorkerEngagement
            ? $engagement->getKey()
            : $engagement;

        $ownedEngagement = WorkerEngagement::withoutGlobalScopes()
            ->whereKey($engagementId)
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [
                WorkerEngagementStatus::Offered->value,
                WorkerEngagementStatus::Accepted->value,
                WorkerEngagementStatus::Active->value,
                WorkerEngagementStatus::Paused->value,
            ])
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query
                    ->where('user_id', $worker->id)
                    ->where('verification_status', WorkerProfile::VERIFICATION_APPROVED)
                    ->where('lifecycle_status', WorkerProfile::LIFECYCLE_VERIFIED);
            })
            ->first();

        if ($ownedEngagement === null) {
            return false;
        }

        return WorkforceCapabilityDefinition::query()
            ->where('capability_key', 'catalog.products.view')
            ->where('is_active', true)
            ->whereHas('permissions', function (Builder $query) use ($ownedEngagement): void {
                $query
                    ->where('worker_engagement_id', $ownedEngagement->id)
                    ->where('allowed', true);
            })
            ->exists();
    }
}
