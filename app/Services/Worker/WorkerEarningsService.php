<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEarningLineType;
use App\Enums\WorkerEarningPeriodStatus;
use App\Enums\WorkerEarningSourceType;
use App\Enums\WorkerEngagementStatus;
use App\Models\User;
use App\Models\Worker\WorkerActivityEvent;
use App\Models\Worker\WorkerEarningApproval;
use App\Models\Worker\WorkerEarningLine;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkerEarningsService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @return array{
     *     engagement: WorkerEngagement,
     *     periods: Collection<int, WorkerEarningPeriod>
     * }
     */
    public function forWorker(User $worker, int $engagementId, bool $includeDraft = true): array
    {
        $engagement = $this->ownedEngagement($worker, $engagementId);
        $workspace = $engagement->workspace;

        if ($workspace === null) {
            throw WorkerEarningsException::workspaceMismatch();
        }

        $periods = $this->workspaceContext->runFor(
            $workspace,
            fn (): Collection => $this->periodsForEngagement($engagement, $includeDraft),
        );

        return [
            'engagement' => $engagement,
            'periods' => $periods,
        ];
    }

    /**
     * @return Collection<int, WorkerEarningPeriod>
     */
    public function forCompany(WorkerEngagement $engagement): Collection
    {
        $this->assertCurrentWorkspace($engagement->workspace_id);

        return $this->periodsForEngagement($engagement);
    }

    public function createPeriod(
        User $actor,
        WorkerEngagement $engagement,
        string $startsAt,
        string $endsAt,
        ?Request $request = null,
    ): WorkerEarningPeriod {
        $workspace = $engagement->workspace;
        if ($workspace === null) {
            throw WorkerEarningsException::workspaceMismatch();
        }

        $this->assertCompanyCanManage($workspace->id, $actor);
        $this->assertCurrentWorkspace($workspace->id);

        $start = CarbonImmutable::parse($startsAt);
        $end = CarbonImmutable::parse($endsAt);
        if ($end->lessThanOrEqualTo($start)) {
            throw WorkerEarningsException::invalidPeriodBoundaries();
        }

        return DB::transaction(function () use (
            $actor,
            $engagement,
            $start,
            $end,
            $request,
        ): WorkerEarningPeriod {
            $lockedEngagement = WorkerEngagement::query()
                ->whereKey($engagement->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEngagementCanReceiveEarnings($lockedEngagement);

            $exists = WorkerEarningPeriod::query()
                ->where('worker_engagement_id', $lockedEngagement->id)
                ->where('starts_at', $start)
                ->where('ends_at', $end)
                ->exists();

            if ($exists) {
                throw WorkerEarningsException::periodAlreadyExists();
            }

            $period = WorkerEarningPeriod::query()->create([
                'workspace_id' => $lockedEngagement->workspace_id,
                'worker_engagement_id' => $lockedEngagement->id,
                'status' => WorkerEarningPeriodStatus::Draft,
                'starts_at' => $start,
                'ends_at' => $end,
                'created_by_user_id' => $actor->id,
            ]);

            $this->auditPeriod(
                action: 'worker.earning_period.created',
                period: $period,
                actor: $actor,
                request: $request,
                newValues: [
                    'status' => WorkerEarningPeriodStatus::Draft->value,
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                ],
            );

            return $period->refresh();
        });
    }

    /**
     * @param array{
     *     line_type:string,
     *     source_type:string,
     *     source_id?:int|null,
     *     source_reference?:string|null,
     *     amount_minor:int|string,
     *     currency:string,
     *     description?:string|null,
     *     adjustment_reason?:string|null
     * } $attributes
     */
    public function createLine(
        User $actor,
        int $engagementId,
        int $periodId,
        array $attributes,
        ?Request $request = null,
    ): WorkerEarningLine {
        $this->assertCurrentWorkspace();

        return DB::transaction(function () use (
            $actor,
            $engagementId,
            $periodId,
            $attributes,
            $request,
        ): WorkerEarningLine {
            $period = WorkerEarningPeriod::query()
                ->with('engagement')
                ->whereKey($periodId)
                ->where('worker_engagement_id', $engagementId)
                ->lockForUpdate()
                ->firstOrFail();

            $engagement = $period->engagement;
            if ($engagement === null) {
                throw WorkerEarningsException::engagementMismatch();
            }

            $this->assertCompanyCanManage($engagement->workspace_id, $actor);
            $this->assertPeriodIsDraft($period);

            $lineType = WorkerEarningLineType::tryFrom((string) $attributes['line_type']);
            $sourceType = WorkerEarningSourceType::tryFrom((string) $attributes['source_type']);
            if (
                ! $lineType instanceof WorkerEarningLineType
                || ! $sourceType instanceof WorkerEarningSourceType
            ) {
                throw WorkerEarningsException::invalidSource();
            }

            $amountMinor = $this->integerMinorAmount($attributes['amount_minor'] ?? null);
            $this->assertLineAmount($lineType, $amountMinor);

            $currency = strtoupper(trim((string) $attributes['currency']));
            if (! preg_match('/\A[A-Z]{3}\z/', $currency)) {
                throw WorkerEarningsException::invalidCurrency();
            }

            $sourceId = ($attributes['source_id'] ?? null) === null
                ? null
                : $this->integerId($attributes['source_id']);
            $this->assertSource(
                engagement: $engagement,
                sourceType: $sourceType,
                sourceId: $sourceId,
            );

            $adjustmentReason = isset($attributes['adjustment_reason'])
                ? trim((string) $attributes['adjustment_reason'])
                : null;
            if (
                $lineType === WorkerEarningLineType::Adjustment
                && $sourceType !== WorkerEarningSourceType::Adjustment
            ) {
                throw WorkerEarningsException::invalidSource();
            }
            if (
                $sourceType === WorkerEarningSourceType::Adjustment
                && $lineType !== WorkerEarningLineType::Adjustment
            ) {
                throw WorkerEarningsException::invalidSource();
            }
            if ($lineType === WorkerEarningLineType::Adjustment && $adjustmentReason === '') {
                throw WorkerEarningsException::adjustmentReasonRequired();
            }

            $line = WorkerEarningLine::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'worker_earning_period_id' => $period->id,
                'line_type' => $lineType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_reference' => $attributes['source_reference'] ?? null,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'description' => $attributes['description'] ?? null,
                'adjustment_reason' => $adjustmentReason,
                'created_by_user_id' => $actor->id,
            ]);

            $this->auditLine(
                action: 'worker.earning_line.created',
                line: $line,
                actor: $actor,
                request: $request,
                newValues: [
                    'line_type' => $lineType->value,
                    'source_type' => $sourceType->value,
                    'source_id' => $sourceId,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'adjustment_reason' => $adjustmentReason,
                ],
            );

            return $line->refresh();
        });
    }

    public function submit(
        User $actor,
        int $engagementId,
        int $periodId,
        ?Request $request = null,
    ): WorkerEarningPeriod {
        return DB::transaction(function () use (
            $actor,
            $engagementId,
            $periodId,
            $request,
        ): WorkerEarningPeriod {
            $period = $this->companyPeriod($actor, $engagementId, $periodId, lock: true);
            $this->assertPeriodHasLines($period);

            $current = $period->status;
            if (
                ! $current instanceof WorkerEarningPeriodStatus
                || ! in_array($current, [
                    WorkerEarningPeriodStatus::Draft,
                    WorkerEarningPeriodStatus::Disputed,
                ], true)
            ) {
                throw WorkerEarningsException::invalidPeriodTransition(
                    $current instanceof WorkerEarningPeriodStatus
                        ? $current
                        : WorkerEarningPeriodStatus::Draft,
                    WorkerEarningPeriodStatus::PendingApproval,
                );
            }

            $period->transitionTo(WorkerEarningPeriodStatus::PendingApproval);
            $this->auditPeriod(
                action: 'worker.earning_period.submitted',
                period: $period,
                actor: $actor,
                request: $request,
                oldValues: ['status' => $current->value],
                newValues: ['status' => WorkerEarningPeriodStatus::PendingApproval->value],
            );

            return $period->refresh();
        });
    }

    public function approve(
        User $actor,
        int $engagementId,
        int $periodId,
        ?string $notes = null,
        ?Request $request = null,
    ): WorkerEarningPeriod {
        return DB::transaction(function () use (
            $actor,
            $engagementId,
            $periodId,
            $notes,
            $request,
        ): WorkerEarningPeriod {
            $period = $this->companyPeriod($actor, $engagementId, $periodId, lock: true);
            if ($period->status !== WorkerEarningPeriodStatus::PendingApproval) {
                throw WorkerEarningsException::invalidPeriodTransition(
                    $period->status instanceof WorkerEarningPeriodStatus
                        ? $period->status
                        : WorkerEarningPeriodStatus::Draft,
                    WorkerEarningPeriodStatus::Approved,
                );
            }

            $this->assertPeriodHasLines($period);
            $totals = $this->totalsFor($period);
            if ($totals['amount_minor'] < 0) {
                throw WorkerEarningsException::negativePeriodTotal();
            }

            $approvedAt = now();
            $period->transitionTo(WorkerEarningPeriodStatus::Approved, [
                'approved_by_user_id' => $actor->id,
                'approved_at' => $approvedAt,
            ]);

            $approval = WorkerEarningApproval::query()->create([
                'workspace_id' => $period->workspace_id,
                'worker_engagement_id' => $period->worker_engagement_id,
                'worker_earning_period_id' => $period->id,
                'approved_by_user_id' => $actor->id,
                'approved_at' => $approvedAt,
                'notes' => $notes,
            ]);

            $this->auditPeriod(
                action: 'worker.earning_period.approved',
                period: $period,
                actor: $actor,
                request: $request,
                oldValues: ['status' => WorkerEarningPeriodStatus::PendingApproval->value],
                newValues: [
                    'status' => WorkerEarningPeriodStatus::Approved->value,
                    'amount_minor' => $totals['amount_minor'],
                    'currency' => $totals['currency'],
                    'approval_id' => $approval->id,
                ],
            );

            return $period->refresh();
        });
    }

    public function dispute(
        User $worker,
        int $engagementId,
        int $periodId,
        ?string $reason = null,
        ?Request $request = null,
    ): WorkerEarningPeriod {
        return DB::transaction(function () use (
            $worker,
            $engagementId,
            $periodId,
            $reason,
            $request,
        ): WorkerEarningPeriod {
            $period = WorkerEarningPeriod::withoutGlobalScopes()
                ->whereKey($periodId)
                ->where('worker_engagement_id', $engagementId)
                ->lockForUpdate()
                ->firstOrFail();

            $engagement = WorkerEngagement::withoutGlobalScopes()
                ->with(['workspace', 'workerProfile'])
                ->whereKey($period->worker_engagement_id)
                ->first();
            if (
                $engagement === null
                || $engagement->workerProfile?->user_id !== $worker->id
            ) {
                abort(404);
            }

            $workspace = $engagement->workspace;
            if ($workspace === null) {
                throw WorkerEarningsException::workspaceMismatch();
            }

            return $this->workspaceContext->runFor($workspace, function () use (
                $period,
                $request,
                $worker,
                $reason,
            ): WorkerEarningPeriod {
                if (
                    ! in_array($period->status, [
                        WorkerEarningPeriodStatus::PendingApproval,
                        WorkerEarningPeriodStatus::Approved,
                    ], true)
                ) {
                    throw WorkerEarningsException::disputeNotAllowed();
                }

                $hasOpenPayout = $period->payouts()
                    ->whereIn('status', ['pending', 'processing'])
                    ->exists();
                if ($hasOpenPayout) {
                    throw WorkerEarningsException::payoutExists();
                }

                $previous = $period->status;
                $period->transitionTo(WorkerEarningPeriodStatus::Disputed);
                $this->auditPeriod(
                    action: 'worker.earning_period.disputed',
                    period: $period,
                    actor: $worker,
                    request: $request,
                    oldValues: ['status' => $previous->value],
                    newValues: array_filter([
                        'status' => WorkerEarningPeriodStatus::Disputed->value,
                        'reason' => $reason !== null ? trim($reason) : null,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                );

                return $period->refresh()->load([
                    'earningLines' => fn (HasMany $query): HasMany => $query->orderBy('id'),
                ]);
            });
        });
    }

    /**
     * @return array{amount_minor:int, currency:string, line_count:int}
     */
    public function totalsFor(WorkerEarningPeriod $period): array
    {
        $lines = WorkerEarningLine::withoutGlobalScopes()
            ->where('worker_earning_period_id', $period->id)
            ->where('workspace_id', $period->workspace_id)
            ->where('worker_engagement_id', $period->worker_engagement_id)
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            throw WorkerEarningsException::periodRequiresLines();
        }

        $currencies = $lines->pluck('currency')->unique()->values();
        if ($currencies->count() !== 1) {
            throw WorkerEarningsException::mixedCurrencies();
        }

        $amountMinor = 0;
        foreach ($lines as $line) {
            $amountMinor += (int) $line->amount_minor;
        }

        return [
            'amount_minor' => $amountMinor,
            'currency' => (string) $currencies->first(),
            'line_count' => $lines->count(),
        ];
    }

    /**
     * @return Collection<int, WorkerEarningPeriod>
     */
    private function periodsForEngagement(WorkerEngagement $engagement, bool $includeDraft = true): Collection
    {
        return WorkerEarningPeriod::query()
            ->where('worker_engagement_id', $engagement->id)
            ->when(
                ! $includeDraft,
                fn ($query) => $query->where('status', '!=', WorkerEarningPeriodStatus::Draft->value),
            )
            ->with([
                'earningLines' => fn (HasMany $query): HasMany => $query
                    ->orderBy('id'),
                'approvals' => fn (HasMany $query): HasMany => $query
                    ->latest('approved_at')
                    ->latest('id'),
                'payouts' => fn (HasMany $query): HasMany => $query
                    ->latest('requested_at')
                    ->latest('id'),
            ])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
    }

    private function companyPeriod(
        User $actor,
        int $engagementId,
        int $periodId,
        bool $lock = false,
    ): WorkerEarningPeriod {
        $periodQuery = WorkerEarningPeriod::query()
            ->with('engagement.workspace')
            ->whereKey($periodId)
            ->where('worker_engagement_id', $engagementId);

        if ($lock) {
            $periodQuery->lockForUpdate();
        }

        $period = $periodQuery->firstOrFail();
        $this->assertCompanyCanManage($period->workspace_id, $actor);

        return $period;
    }

    private function ownedEngagement(User $worker, int $engagementId): WorkerEngagement
    {
        return WorkerEngagement::withoutGlobalScopes()
            ->with(['workspace', 'workerProfile'])
            ->whereKey($engagementId)
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            })
            ->firstOrFail();
    }

    private function assertCompanyCanManage(int $workspaceId, User $actor): void
    {
        $workspace = Workspace::query()->find($workspaceId);
        if (
            $workspace === null
            || ! $this->workspaceAccess->canManageMembers($actor, $workspace)
        ) {
            throw new AuthorizationException('You are not authorized to manage worker earnings.');
        }
    }

    private function assertCurrentWorkspace(?int $workspaceId = null): void
    {
        $currentWorkspaceId = $this->workspaceContext->workspaceId();
        if (
            $currentWorkspaceId === null
            || ($workspaceId !== null && (int) $currentWorkspaceId !== $workspaceId)
        ) {
            throw WorkerEarningsException::workspaceMismatch();
        }
    }

    private function assertEngagementCanReceiveEarnings(WorkerEngagement $engagement): void
    {
        if (
            ! $engagement->status instanceof WorkerEngagementStatus
            || ! in_array($engagement->status, [
                WorkerEngagementStatus::Active,
                WorkerEngagementStatus::Paused,
                WorkerEngagementStatus::Completed,
                WorkerEngagementStatus::Terminated,
            ], true)
        ) {
            throw WorkerEarningsException::engagementNotEligible();
        }
    }

    private function assertPeriodIsDraft(WorkerEarningPeriod $period): void
    {
        if ($period->status !== WorkerEarningPeriodStatus::Draft) {
            throw WorkerEarningsException::periodImmutable();
        }
    }

    private function assertPeriodHasLines(WorkerEarningPeriod $period): void
    {
        if (! WorkerEarningLine::query()
            ->where('worker_earning_period_id', $period->id)
            ->exists()) {
            throw WorkerEarningsException::periodRequiresLines();
        }
    }

    private function assertLineAmount(WorkerEarningLineType $lineType, int $amountMinor): void
    {
        if ($amountMinor === 0) {
            throw WorkerEarningsException::invalidLineAmount();
        }

        if (
            $lineType === WorkerEarningLineType::Deduction
            && $amountMinor > 0
        ) {
            throw WorkerEarningsException::deductionMustBeNegative();
        }

        if (
            in_array($lineType, [
                WorkerEarningLineType::Base,
                WorkerEarningLineType::Commission,
                WorkerEarningLineType::Bonus,
            ], true)
            && $amountMinor < 0
        ) {
            throw WorkerEarningsException::earningLineMustBePositive();
        }
    }

    private function assertSource(
        WorkerEngagement $engagement,
        WorkerEarningSourceType $sourceType,
        ?int $sourceId,
    ): void {
        if (
            in_array($sourceType, [
                WorkerEarningSourceType::EngagementTerms,
                WorkerEarningSourceType::Adjustment,
            ], true)
            && $sourceId !== null
        ) {
            throw WorkerEarningsException::sourceIdNotAllowed();
        }

        if (
            in_array($sourceType, [
                WorkerEarningSourceType::WorkSession,
                WorkerEarningSourceType::ActivityEvent,
            ], true)
            && $sourceId === null
        ) {
            throw WorkerEarningsException::invalidSource();
        }

        if ($sourceType === WorkerEarningSourceType::WorkSession && ! WorkerWorkSession::withoutGlobalScopes()
            ->whereKey($sourceId)
            ->where('workspace_id', $engagement->workspace_id)
            ->where('worker_engagement_id', $engagement->id)
            ->exists()) {
            throw WorkerEarningsException::invalidSource();
        }

        if ($sourceType === WorkerEarningSourceType::ActivityEvent && ! WorkerActivityEvent::withoutGlobalScopes()
            ->whereKey($sourceId)
            ->where('workspace_id', $engagement->workspace_id)
            ->where('worker_engagement_id', $engagement->id)
            ->where('worker_profile_id', $engagement->worker_profile_id)
            ->exists()) {
            throw WorkerEarningsException::invalidSource();
        }
    }

    private function integerMinorAmount(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || ! preg_match('/\A[+-]?\d+\z/', trim($value))) {
            throw WorkerEarningsException::invalidLineAmount();
        }

        $normalized = trim($value);
        $parsed = filter_var($normalized, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw WorkerEarningsException::invalidLineAmount();
        }

        return (int) $parsed;
    }

    private function integerId(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($parsed === false) {
            throw WorkerEarningsException::invalidSource();
        }

        return (int) $parsed;
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function auditPeriod(
        string $action,
        WorkerEarningPeriod $period,
        User $actor,
        ?Request $request,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        $this->auditLog->log(
            action: $action,
            entityType: WorkerEarningPeriod::class,
            entityId: $period->id,
            oldValues: $oldValues,
            newValues: $newValues,
            actor: $actor,
            request: $request,
            meta: [
                'worker_engagement_id' => $period->worker_engagement_id,
            ],
            workspaceId: $period->workspace_id,
        );
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function auditLine(
        string $action,
        WorkerEarningLine $line,
        User $actor,
        ?Request $request,
        array $newValues,
    ): void {
        $this->auditLog->log(
            action: $action,
            entityType: WorkerEarningLine::class,
            entityId: $line->id,
            newValues: $newValues,
            actor: $actor,
            request: $request,
            meta: [
                'worker_engagement_id' => $line->worker_engagement_id,
                'worker_earning_period_id' => $line->worker_earning_period_id,
            ],
            workspaceId: $line->workspace_id,
        );
    }
}
