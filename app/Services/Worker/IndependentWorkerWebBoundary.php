<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkforceParticipantType;
use App\Models\User;

/**
 * Web surface allowlist for an independent worker.
 *
 * Company members and platform admins are outside this boundary.
 * Engagement capability checks remain a separate authorization layer.
 */
final class IndependentWorkerWebBoundary
{
    /**
     * Named web routes an independent worker may reach.
     *
     * @var list<string>
     */
    public const ALLOWED_ROUTE_NAMES = [
        'worker.inbox.home',
        'worker.inbox.index',
        'worker.inbox.show',
        'worker.inbox.messages.store',
        'logout',
        'verification.verify',
    ];

    public function isIndependentWorker(mixed $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (! $user->workerProfile()->exists()) {
            return false;
        }

        return ! $user->workspaces()->wherePivot('status', 'active')->exists();
    }

    /**
     * Stable mobile/web participant identity.
     * A company member who also has a worker profile stays company staff.
     *
     * @return array{type: string, worker_profile_id: int|null}
     */
    public function participant(User $user): array
    {
        if (! $this->isIndependentWorker($user)) {
            return [
                'type' => WorkforceParticipantType::CompanyStaff->value,
                'worker_profile_id' => null,
            ];
        }

        $profileId = $user->workerProfile()->value('id');

        return [
            'type' => WorkforceParticipantType::IndependentWorker->value,
            'worker_profile_id' => is_numeric($profileId) ? (int) $profileId : null,
        ];
    }

    public function allows(?string $routeName): bool
    {
        return $routeName !== null
            && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true);
    }
}
