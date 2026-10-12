<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Models\Worker\WorkerWorkExperience;
use App\Models\Workspace;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

final class WorkerDiscoveryService
{
    private const DEFAULT_PER_PAGE = 12;

    private const MAX_PER_PAGE = 24;

    /** @var list<WorkerEngagementStatus> */
    private const OPEN_ENGAGEMENT_STATUSES = [
        WorkerEngagementStatus::Draft,
        WorkerEngagementStatus::Offered,
        WorkerEngagementStatus::Accepted,
        WorkerEngagementStatus::Active,
        WorkerEngagementStatus::Paused,
    ];

    /** @var list<WorkerEngagementStatus> */
    private const OPEN_CONTRACT_STATUSES = [
        WorkerEngagementStatus::Accepted,
        WorkerEngagementStatus::Active,
        WorkerEngagementStatus::Paused,
    ];

    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function search(
        User $actor,
        Workspace $workspace,
        array $filters = [],
    ): LengthAwarePaginator {
        $this->assertCompanyCanDiscover($actor, $workspace);

        $query = $this->eligibleQuery($workspace);
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';

                $query
                    ->where('display_name', 'like', $like)
                    ->orWhere('professional_summary', 'like', $like)
                    ->orWhere('sales_experience_level', 'like', $like);
            });
        }

        if (filled($filters['country_code'] ?? null)) {
            $query->where('country_code', (string) $filters['country_code']);
        }

        if (filled($filters['availability_status'] ?? null)) {
            $query->where('availability_status', (string) $filters['availability_status']);
        }

        if (filled($filters['sales_experience_level'] ?? null)) {
            $query->where(
                'sales_experience_level',
                (string) $filters['sales_experience_level'],
            );
        }

        if (filled($filters['skill_key'] ?? null)) {
            $query->whereHas('skills', function (Builder $query) use ($filters): void {
                $query->where('skill_key', (string) $filters['skill_key']);
            });
        }

        $perPage = min(
            max((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $profiles = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return $this->mapPaginator($profiles, $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function profileForCompany(
        User $actor,
        Workspace $workspace,
        int $workerProfileId,
    ): array {
        $this->assertCompanyCanDiscover($actor, $workspace);

        $profile = $this->eligibleQuery($workspace)
            ->whereKey($workerProfileId)
            ->firstOrFail();

        return $this->publicProfile($profile, $workspace);
    }

    /**
     * @return Collection<int, WorkerEngagement>
     */
    public function openContracts(User $actor, Workspace $workspace): Collection
    {
        $this->assertCompanyCanDiscover($actor, $workspace);

        return WorkerEngagement::query()
            ->select([
                'id',
                'workspace_id',
                'worker_profile_id',
                'status',
                'job_title',
                'starts_at',
                'ends_at',
                'duration_value',
                'duration_unit',
            ])
            ->where('workspace_id', $workspace->id)
            ->whereIn(
                'status',
                array_map(
                    static fn (WorkerEngagementStatus $status): string => $status->value,
                    self::OPEN_CONTRACT_STATUSES,
                ),
            )
            ->with([
                'workerProfile:id,display_name,profile_photo',
                'guide:id,workspace_id,worker_engagement_id',
            ])
            ->withCount([
                'permissions as granted_capabilities_count' => function (Builder $query): void {
                    $query->where('allowed', true);
                },
                'assignedProducts as assigned_products_count',
            ])
            ->orderByDesc('id')
            ->get();
    }

    public function assertOfferAllowed(
        User $actor,
        Workspace $workspace,
        int $workerProfileId,
    ): WorkerProfile {
        $this->assertCompanyCanDiscover($actor, $workspace);

        $profile = $this->eligibleQuery($workspace)
            ->whereKey($workerProfileId)
            ->first();

        if ($profile === null) {
            throw WorkerDiscoveryException::workerNotDiscoverable();
        }

        if ($this->hasOpenEngagement($profile, $workspace)) {
            throw WorkerDiscoveryException::openEngagementExists();
        }

        return $profile;
    }

    private function eligibleQuery(Workspace $workspace): Builder
    {
        return WorkerProfile::query()
            ->select([
                'id',
                'display_name',
                'profile_photo',
                'country_code',
                'professional_summary',
                'sales_experience_level',
                'availability_status',
                'updated_at',
            ])
            ->where('verification_status', WorkerProfile::VERIFICATION_APPROVED)
            ->where('lifecycle_status', WorkerProfile::LIFECYCLE_VERIFIED)
            ->where('availability_status', '!=', WorkerProfile::AVAILABILITY_UNAVAILABLE)
            ->whereIn('country_code', WorkerProfile::COUNTRIES)
            ->whereNotNull('nationality')
            ->where('nationality', '!=', '')
            ->whereNotNull('display_name')
            ->where('display_name', '!=', '')
            ->whereNotNull('professional_summary')
            ->where('professional_summary', '!=', '')
            ->whereNotNull('sales_experience_level')
            ->where('sales_experience_level', '!=', '')
            ->whereHas('discoverySettings', function (Builder $query): void {
                $query->where('is_discoverable', true);
            })
            ->with([
                'skills',
                'languages',
                'experiences',
                'engagements' => function (HasMany $query) use ($workspace): void {
                    $query
                        ->select([
                            'id',
                            'workspace_id',
                            'worker_profile_id',
                            'status',
                            'job_title',
                            'starts_at',
                            'ends_at',
                            'duration_value',
                            'duration_unit',
                        ])
                        ->where('workspace_id', $workspace->id)
                        ->orderByDesc('id');
                },
            ]);
    }

    /**
     * @param  LengthAwarePaginator<int, WorkerProfile>  $profiles
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function mapPaginator(
        LengthAwarePaginator $profiles,
        Workspace $workspace,
    ): LengthAwarePaginator {
        $profiles->setCollection(
            $profiles->getCollection()->map(
                fn (WorkerProfile $profile): array => $this->publicProfile($profile, $workspace),
            ),
        );

        return $profiles;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicProfile(WorkerProfile $profile, Workspace $workspace): array
    {
        $engagement = $this->currentWorkspaceEngagement($profile, $workspace);

        return [
            'id' => (int) $profile->id,
            'display_name' => (string) $profile->display_name,
            'profile_photo' => filled($profile->profile_photo)
                ? Storage::disk('local')->url($profile->profile_photo)
                : null,
            'country_code' => (string) $profile->country_code,
            'professional_summary' => (string) $profile->professional_summary,
            'sales_experience_level' => (string) $profile->sales_experience_level,
            'availability_status' => (string) $profile->availability_status,
            'verified_badge' => true,
            'skills' => $profile->skills->map(fn (WorkerSkill $skill): array => [
                'id' => (int) $skill->id,
                'skill_key' => (string) $skill->skill_key,
                'display_name' => (string) $skill->display_name,
            ])->values()->all(),
            'languages' => $profile->languages->map(fn ($language): array => [
                'language_code' => (string) $language->language_code,
            ])->values()->all(),
            'experiences' => $profile->experiences->map(fn (WorkerWorkExperience $experience): array => [
                'id' => (int) $experience->id,
                'title' => (string) $experience->title,
                'organization_name' => (string) $experience->organization_name,
                'started_on' => $experience->started_on?->toDateString(),
                'ended_on' => $experience->ended_on?->toDateString(),
                'is_current' => (bool) $experience->is_current,
                'description' => $experience->description,
            ])->values()->all(),
            'existing_engagement' => $engagement === null
                ? null
                : [
                    'id' => (int) $engagement->id,
                    'status' => $engagement->status instanceof WorkerEngagementStatus
                        ? $engagement->status->value
                        : (string) $engagement->status,
                    'job_title' => $engagement->job_title,
                    'starts_at' => $engagement->starts_at?->toDateString(),
                    'ends_at' => $engagement->ends_at?->toDateString(),
                    'duration_value' => $engagement->duration_value,
                    'duration_unit' => $engagement->duration_unit?->value,
                ],
        ];
    }

    private function currentWorkspaceEngagement(
        WorkerProfile $profile,
        Workspace $workspace,
    ): ?WorkerEngagement {
        $engagements = $profile->relationLoaded('engagements')
            ? $profile->engagements
            : collect();
        $engagements = $engagements
            ->filter(fn (WorkerEngagement $engagement): bool => (int) $engagement->workspace_id === (int) $workspace->id);

        return $engagements->first(
            fn (WorkerEngagement $engagement): bool => $this->isOpenStatus($engagement->status),
        ) ?? $engagements->first();
    }

    private function hasOpenEngagement(
        WorkerProfile $profile,
        Workspace $workspace,
    ): bool {
        $engagement = $this->currentWorkspaceEngagement($profile, $workspace);

        return $engagement !== null && $this->isOpenStatus($engagement->status);
    }

    private function isOpenStatus(WorkerEngagementStatus|string $status): bool
    {
        $status = $status instanceof WorkerEngagementStatus
            ? $status
            : WorkerEngagementStatus::tryFrom($status);

        return $status instanceof WorkerEngagementStatus
            && in_array($status, self::OPEN_ENGAGEMENT_STATUSES, true);
    }

    private function assertCompanyCanDiscover(User $actor, Workspace $workspace): void
    {
        if (
            (int) $this->workspaceContext->workspaceId() !== (int) $workspace->id
            || ! $this->workspaceAccess->isActiveMember($actor, $workspace)
        ) {
            throw WorkerDiscoveryException::workspaceAccessDenied();
        }
    }
}
