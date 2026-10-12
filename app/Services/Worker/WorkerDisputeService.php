<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerDisputeReporterType;
use App\Enums\WorkerDisputeStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerDispute;
use App\Models\Worker\WorkerEngagement;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkerDisputeService
{
    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 50;

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function companyIndex(
        User $actor,
        Workspace $workspace,
        array $filters = [],
    ): LengthAwarePaginator {
        $this->assertCompanyAccess($actor, $workspace);

        return $this->workspaceContext->runFor($workspace, function () use ($filters): LengthAwarePaginator {
            $query = WorkerDispute::query()
                ->select($this->publicColumns())
                ->with([
                    'engagement:id,workspace_id,worker_profile_id,job_title,status',
                    'engagement.workerProfile:id,display_name',
                ]);

            return $this->applyFilters($query, $filters)
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->paginate($this->pageSize($filters))
                ->withQueryString();
        });
    }

    public function companyShow(
        User $actor,
        Workspace $workspace,
        int $disputeId,
    ): WorkerDispute {
        $this->assertCompanyAccess($actor, $workspace);

        return $this->workspaceContext->runFor($workspace, function () use ($disputeId): WorkerDispute {
            $dispute = WorkerDispute::query()
                ->select($this->publicColumns())
                ->with([
                    'engagement:id,workspace_id,worker_profile_id,job_title,status',
                    'engagement.workerProfile:id,display_name',
                ])
                ->whereKey($disputeId)
                ->first();

            if (! $dispute instanceof WorkerDispute) {
                throw $this->notFound($disputeId);
            }

            return $dispute;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function workerIndex(
        User $worker,
        array $filters = [],
    ): LengthAwarePaginator {
        $query = WorkerDispute::withoutGlobalScopes()
            ->select($this->publicColumns())
            ->whereHas('engagement', function (Builder $query) use ($worker): void {
                $query
                    ->withoutGlobalScopes()
                    ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                        $query->where('user_id', $worker->id);
                    });
            })
            ->with([
                'engagement' => function ($query): void {
                    $query
                        ->withoutGlobalScopes()
                        ->select('id', 'workspace_id', 'worker_profile_id', 'job_title', 'status');
                },
                'engagement.workspace:id,name',
                'engagement.workerProfile:id,display_name',
            ]);

        return $this->applyFilters($query, $filters)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters))
            ->withQueryString();
    }

    public function workerShow(User $worker, int $disputeId): WorkerDispute
    {
        $dispute = WorkerDispute::withoutGlobalScopes()
            ->select($this->publicColumns())
            ->whereKey($disputeId)
            ->whereHas('engagement', function (Builder $query) use ($worker): void {
                $query
                    ->withoutGlobalScopes()
                    ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                        $query->where('user_id', $worker->id);
                    });
            })
            ->with([
                'engagement' => function ($query): void {
                    $query
                        ->withoutGlobalScopes()
                        ->select('id', 'workspace_id', 'worker_profile_id', 'job_title', 'status');
                },
                'engagement.workspace:id,name',
                'engagement.workerProfile:id,display_name',
            ])
            ->first();

        if (! $dispute instanceof WorkerDispute) {
            throw $this->notFound($disputeId);
        }

        return $dispute;
    }

    /**
     * @param  array{category:string, description:string}  $attributes
     */
    public function openForWorker(
        User $worker,
        int $engagementId,
        array $attributes,
        ?Request $request = null,
    ): WorkerDispute {
        $engagement = $this->ownedEngagement($worker, $engagementId);
        $workspace = $engagement->workspace;

        if (! $workspace instanceof Workspace) {
            throw WorkerDisputeException::workspaceMismatch();
        }

        return $this->workspaceContext->runFor($workspace, function () use (
            $worker,
            $engagement,
            $attributes,
            $request,
        ): WorkerDispute {
            return $this->create(
                engagement: $engagement,
                reporterType: WorkerDisputeReporterType::Worker,
                reporter: $worker,
                attributes: $attributes,
                request: $request,
            );
        });
    }

    /**
     * @param  array{category:string, description:string}  $attributes
     */
    public function openForCompany(
        User $actor,
        Workspace $workspace,
        WorkerEngagement $engagement,
        array $attributes,
        ?Request $request = null,
    ): WorkerDispute {
        $this->assertCompanyAccess($actor, $workspace);

        if ((int) $engagement->workspace_id !== (int) $workspace->id) {
            throw WorkerDisputeException::workspaceMismatch();
        }

        return $this->workspaceContext->runFor($workspace, function () use (
            $actor,
            $engagement,
            $attributes,
            $request,
        ): WorkerDispute {
            $scopedEngagement = WorkerEngagement::query()
                ->whereKey($engagement->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->create(
                engagement: $scopedEngagement,
                reporterType: WorkerDisputeReporterType::Company,
                reporter: $actor,
                attributes: $attributes,
                request: $request,
            );
        });
    }

    /**
     * @param  array{category:string, description:string}  $attributes
     */
    public function openForPlatform(
        PlatformAdmin $admin,
        int $engagementId,
        array $attributes,
        ?Request $request = null,
    ): WorkerDispute {
        $this->assertPlatformAdmin($admin);

        return DB::transaction(function () use (
            $admin,
            $engagementId,
            $attributes,
            $request,
        ): WorkerDispute {
            $engagement = WorkerEngagement::withoutGlobalScopes()
                ->whereKey($engagementId)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->create(
                engagement: $engagement,
                reporterType: WorkerDisputeReporterType::PlatformAdmin,
                reporter: $admin,
                attributes: $attributes,
                request: $request,
            );
        });
    }

    public function beginReview(
        PlatformAdmin $admin,
        int $disputeId,
        ?string $reviewNotes = null,
        ?Request $request = null,
    ): WorkerDispute {
        return $this->transitionForPlatform(
            admin: $admin,
            disputeId: $disputeId,
            next: WorkerDisputeStatus::UnderReview,
            attributes: array_filter([
                'reviewer_platform_admin_id' => $admin->id,
                'under_review_at' => now(),
                'review_notes' => $this->nullableText($reviewNotes),
            ], static fn (mixed $value): bool => $value !== null),
            request: $request,
        );
    }

    public function resolve(
        PlatformAdmin $admin,
        int $disputeId,
        string $resolutionSummary,
        ?string $reviewNotes = null,
        ?Request $request = null,
    ): WorkerDispute {
        $resolutionSummary = trim($resolutionSummary);
        if ($resolutionSummary === '') {
            throw WorkerDisputeException::resolutionSummaryRequired();
        }

        return $this->transitionForPlatform(
            admin: $admin,
            disputeId: $disputeId,
            next: WorkerDisputeStatus::Resolved,
            attributes: array_filter([
                'reviewer_platform_admin_id' => $admin->id,
                'resolved_at' => now(),
                'resolution_summary' => $resolutionSummary,
                'review_notes' => $this->nullableText($reviewNotes),
            ], static fn (mixed $value): bool => $value !== null),
            request: $request,
        );
    }

    public function close(
        PlatformAdmin $admin,
        int $disputeId,
        ?string $reviewNotes = null,
        ?Request $request = null,
    ): WorkerDispute {
        return $this->transitionForPlatform(
            admin: $admin,
            disputeId: $disputeId,
            next: WorkerDisputeStatus::Closed,
            attributes: array_filter([
                'reviewer_platform_admin_id' => $admin->id,
                'closed_at' => now(),
                'review_notes' => $this->nullableText($reviewNotes),
            ], static fn (mixed $value): bool => $value !== null),
            request: $request,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function platformIndex(
        PlatformAdmin $admin,
        array $filters = [],
    ): LengthAwarePaginator {
        $this->assertPlatformAdmin($admin);

        $query = WorkerDispute::withoutGlobalScopes()
            ->select($this->publicColumns())
            ->with([
                'engagement' => function ($query): void {
                    $query
                        ->withoutGlobalScopes()
                        ->select('id', 'workspace_id', 'worker_profile_id', 'job_title', 'status');
                },
                'engagement.workspace:id,name',
                'engagement.workerProfile:id,display_name',
            ]);

        return $this->applyFilters($query, $filters)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters))
            ->withQueryString();
    }

    public function platformShow(PlatformAdmin $admin, int $disputeId): WorkerDispute
    {
        $this->assertPlatformAdmin($admin);

        $dispute = WorkerDispute::withoutGlobalScopes()
            ->with([
                'engagement' => function ($query): void {
                    $query
                        ->withoutGlobalScopes()
                        ->select('id', 'workspace_id', 'worker_profile_id', 'job_title', 'status');
                },
                'engagement.workspace:id,name',
                'engagement.workerProfile:id,display_name',
                'reportedBy:id,name',
                'reportedByPlatformAdmin:id,name',
                'reviewer:id,name',
            ])
            ->whereKey($disputeId)
            ->first();

        if (! $dispute instanceof WorkerDispute) {
            throw $this->notFound($disputeId);
        }

        return $dispute;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(
        WorkerEngagement $engagement,
        WorkerDisputeReporterType $reporterType,
        User|PlatformAdmin $reporter,
        array $attributes,
        ?Request $request,
    ): WorkerDispute {
        $category = trim((string) ($attributes['category'] ?? ''));
        $description = trim((string) ($attributes['description'] ?? ''));

        if ($category === '') {
            throw WorkerDisputeException::categoryRequired();
        }

        if ($description === '') {
            throw WorkerDisputeException::descriptionRequired();
        }

        $isPlatformReporter = $reporter instanceof PlatformAdmin;

        $disputeQuery = $isPlatformReporter
            ? WorkerDispute::withoutGlobalScopes()
            : WorkerDispute::query();

        $dispute = $disputeQuery->create([
            'workspace_id' => $engagement->workspace_id,
            'worker_engagement_id' => $engagement->id,
            'reporter_type' => $reporterType,
            'reported_by_user_id' => $isPlatformReporter ? null : $reporter->id,
            'reported_by_platform_admin_id' => $isPlatformReporter ? $reporter->id : null,
            'category' => $category,
            'description' => $description,
            'status' => WorkerDisputeStatus::Open,
            'opened_at' => now(),
        ]);

        $this->audit(
            action: 'worker.dispute.opened',
            dispute: $dispute,
            user: $isPlatformReporter ? null : $reporter,
            platformAdmin: $isPlatformReporter ? $reporter : null,
            request: $request,
            oldValues: null,
            newValues: [
                'status' => WorkerDisputeStatus::Open->value,
                'reporter_type' => $reporterType->value,
                'category' => $category,
            ],
        );

        return $dispute->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transitionForPlatform(
        PlatformAdmin $admin,
        int $disputeId,
        WorkerDisputeStatus $next,
        array $attributes,
        ?Request $request,
    ): WorkerDispute {
        $this->assertPlatformAdmin($admin);

        return DB::transaction(function () use (
            $admin,
            $disputeId,
            $next,
            $attributes,
            $request,
        ): WorkerDispute {
            $dispute = WorkerDispute::withoutGlobalScopes()
                ->whereKey($disputeId)
                ->lockForUpdate()
                ->first();

            if (! $dispute instanceof WorkerDispute) {
                throw $this->notFound($disputeId);
            }

            $previous = $dispute->status;
            $dispute->transitionTo($next, $attributes);

            $this->audit(
                action: 'worker.dispute.transitioned',
                dispute: $dispute,
                user: null,
                platformAdmin: $admin,
                request: $request,
                oldValues: [
                    'status' => $previous instanceof WorkerDisputeStatus
                        ? $previous->value
                        : (string) $dispute->getRawOriginal('status'),
                ],
                newValues: [
                    'status' => $next->value,
                ],
            );

            return $dispute->refresh();
        });
    }

    private function ownedEngagement(User $worker, int $engagementId): WorkerEngagement
    {
        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->with(['workspace', 'workerProfile'])
            ->whereKey($engagementId)
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            })
            ->first();

        if (! $engagement instanceof WorkerEngagement) {
            throw $this->notFound($engagementId);
        }

        return $engagement;
    }

    private function assertCompanyAccess(User $actor, Workspace $workspace): void
    {
        if (
            (int) $this->workspaceContext->workspaceId() !== (int) $workspace->id
            || ! $this->workspaceAccess->isActiveMember($actor, $workspace)
        ) {
            throw new AuthorizationException('You are not authorized to access worker disputes.');
        }
    }

    private function assertPlatformAdmin(PlatformAdmin $admin): void
    {
        if (
            ! auth('platform_admin')->check()
            || (int) auth('platform_admin')->id() !== (int) $admin->id
        ) {
            throw new AuthorizationException('Platform admin authentication is required.');
        }
    }

    /**
     * @param  Builder<WorkerDispute>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<WorkerDispute>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $status = WorkerDisputeStatus::tryFrom((string) ($filters['status'] ?? ''));
        $from = $this->filterDate($filters['from'] ?? null, endOfDay: false);
        $to = $this->filterDate($filters['to'] ?? null, endOfDay: true);

        return $query
            ->when(
                $status instanceof WorkerDisputeStatus,
                fn (Builder $query): Builder => $query->where('status', $status->value),
            )
            ->when(
                $from instanceof CarbonImmutable,
                fn (Builder $query): Builder => $query->where('opened_at', '>=', $from),
            )
            ->when(
                $to instanceof CarbonImmutable,
                fn (Builder $query): Builder => $query->where('opened_at', '<=', $to),
            );
    }

    private function filterDate(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));
        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function pageSize(array $filters): int
    {
        return min(
            max((int) ($filters['per_page'] ?? self::DEFAULT_PAGE_SIZE), 1),
            self::MAX_PAGE_SIZE,
        );
    }

    /**
     * @return list<string>
     */
    private function publicColumns(): array
    {
        return [
            'id',
            'workspace_id',
            'worker_engagement_id',
            'reporter_type',
            'category',
            'description',
            'status',
            'resolution_summary',
            'opened_at',
            'under_review_at',
            'resolved_at',
            'closed_at',
            'created_at',
        ];
    }

    private function nullableText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function audit(
        string $action,
        WorkerDispute $dispute,
        ?User $user,
        ?PlatformAdmin $platformAdmin,
        ?Request $request,
        ?array $oldValues,
        ?array $newValues,
    ): void {
        $meta = [
            'worker_engagement_id' => $dispute->worker_engagement_id,
            'reporter_type' => $dispute->reporter_type instanceof WorkerDisputeReporterType
                ? $dispute->reporter_type->value
                : (string) $dispute->reporter_type,
        ];

        if ($platformAdmin instanceof PlatformAdmin) {
            $meta['platform_admin_id'] = $platformAdmin->id;
        }

        $this->auditLog->log(
            action: $action,
            entityType: WorkerDispute::class,
            entityId: $dispute->id,
            oldValues: $oldValues,
            newValues: $newValues,
            actor: $user,
            request: $request,
            meta: $meta,
            workspaceId: $dispute->workspace_id,
        );
    }

    private function notFound(int $id): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel(WorkerDispute::class, [$id]);
    }
}
