<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEarningPeriodStatus;
use App\Enums\WorkerPayoutStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerPayoutRecord;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkerPayoutService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly WorkerEarningsService $earnings,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @return Collection<int, WorkerPayoutRecord>
     */
    public function forCompany(WorkerEngagement $engagement): Collection
    {
        if ((int) $this->workspaceContext->workspaceId() !== (int) $engagement->workspace_id) {
            throw WorkerPayoutException::periodMismatch();
        }

        return WorkerPayoutRecord::query()
            ->where('worker_engagement_id', $engagement->id)
            ->with(['period', 'workerProfile'])
            ->latest('requested_at')
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, WorkerPayoutRecord>
     */
    public function forWorker(User $worker, ?int $engagementId = null): Collection
    {
        if ($engagementId === null) {
            return WorkerPayoutRecord::withoutGlobalScopes()
                ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                    $query->where('user_id', $worker->id);
                })
                ->latest('requested_at')
                ->latest('id')
                ->get();
        }

        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->with(['workspace', 'workerProfile'])
            ->whereKey($engagementId)
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            })
            ->firstOrFail();

        if ($engagement->workspace === null) {
            throw WorkerPayoutException::periodMismatch();
        }

        return $this->workspaceContext->runFor(
            $engagement->workspace,
            fn (): Collection => $this->forCompany($engagement),
        );
    }

    /**
     * @return Collection<int, WorkerPayoutRecord>
     */
    public function forPlatform(): Collection
    {
        $payouts = WorkerPayoutRecord::withoutGlobalScopes()
            ->with('workerProfile')
            ->latest('requested_at')
            ->latest('id')
            ->limit(100)
            ->get();

        $engagements = WorkerEngagement::withoutGlobalScopes()
            ->with('workspace')
            ->whereIn('id', $payouts->pluck('worker_engagement_id'))
            ->get()
            ->keyBy('id');
        $periods = WorkerEarningPeriod::withoutGlobalScopes()
            ->whereIn('id', $payouts->pluck('worker_earning_period_id'))
            ->get()
            ->keyBy('id');

        $payouts->each(function (WorkerPayoutRecord $payout) use ($engagements, $periods): void {
            $payout->setRelation('engagement', $engagements->get($payout->worker_engagement_id));
            $payout->setRelation('period', $periods->get($payout->worker_earning_period_id));
        });

        return $payouts;
    }

    /**
     * @return Collection<int, WorkerEarningPeriod>
     */
    public function approvedPeriodsForPlatform(): Collection
    {
        $periods = WorkerEarningPeriod::withoutGlobalScopes()
            ->where('status', WorkerEarningPeriodStatus::Approved->value)
            ->latest('starts_at')
            ->latest('id')
            ->limit(100)
            ->get();

        $engagements = WorkerEngagement::withoutGlobalScopes()
            ->with(['workspace', 'workerProfile'])
            ->whereIn('id', $periods->pluck('worker_engagement_id'))
            ->get()
            ->keyBy('id');

        $periods->each(function (WorkerEarningPeriod $period) use ($engagements): void {
            $period->setRelation('engagement', $engagements->get($period->worker_engagement_id));
        });

        return $periods;
    }

    public function create(
        PlatformAdmin $admin,
        int $periodId,
        ?Request $request = null,
    ): WorkerPayoutRecord {
        return DB::transaction(function () use ($admin, $periodId, $request): WorkerPayoutRecord {
            $period = WorkerEarningPeriod::withoutGlobalScopes()
                ->whereKey($periodId)
                ->lockForUpdate()
                ->firstOrFail();
            $engagement = WorkerEngagement::withoutGlobalScopes()
                ->with(['workspace', 'workerProfile'])
                ->whereKey($period->worker_engagement_id)
                ->first();

            if ($engagement === null || $engagement->workspace === null) {
                throw WorkerPayoutException::periodMismatch();
            }

            return $this->workspaceContext->runFor(
                $engagement->workspace,
                function () use ($admin, $period, $engagement, $request): WorkerPayoutRecord {
                    if (
                        $period->workspace_id !== $engagement->workspace_id
                        || $period->worker_engagement_id !== $engagement->id
                    ) {
                        throw WorkerPayoutException::periodMismatch();
                    }

                    if ($period->status !== WorkerEarningPeriodStatus::Approved) {
                        throw WorkerPayoutException::payoutNotEligible();
                    }

                    $totals = $this->earnings->totalsFor($period);
                    if ($totals['amount_minor'] <= 0) {
                        throw WorkerPayoutException::invalidAmount();
                    }

                    if (! preg_match('/\A[A-Z]{3}\z/', $totals['currency'])) {
                        throw WorkerPayoutException::invalidCurrency();
                    }

                    $openPayoutExists = WorkerPayoutRecord::query()
                        ->where('worker_earning_period_id', $period->id)
                        ->whereIn('status', [
                            WorkerPayoutStatus::Pending->value,
                            WorkerPayoutStatus::Processing->value,
                        ])
                        ->exists();
                    if ($openPayoutExists) {
                        throw WorkerPayoutException::openPayoutExists();
                    }

                    $payout = WorkerPayoutRecord::query()->create([
                        'workspace_id' => $period->workspace_id,
                        'worker_profile_id' => $engagement->worker_profile_id,
                        'worker_engagement_id' => $engagement->id,
                        'worker_earning_period_id' => $period->id,
                        'amount_minor' => $totals['amount_minor'],
                        'currency' => $totals['currency'],
                        'status' => WorkerPayoutStatus::Pending,
                        'created_by_platform_admin_id' => $admin->id,
                        'requested_at' => now(),
                    ]);

                    $this->auditPlatform(
                        action: 'worker.payout.created',
                        payout: $payout,
                        admin: $admin,
                        request: $request,
                        newValues: [
                            'status' => WorkerPayoutStatus::Pending->value,
                            'amount_minor' => $payout->amount_minor,
                            'currency' => $payout->currency,
                        ],
                    );

                    return $payout->refresh();
                },
            );
        });
    }

    public function transition(
        PlatformAdmin $admin,
        int $payoutId,
        WorkerPayoutStatus $next,
        ?string $providerReference = null,
        ?string $reason = null,
        ?Request $request = null,
    ): WorkerPayoutRecord {
        return DB::transaction(function () use (
            $admin,
            $payoutId,
            $next,
            $providerReference,
            $reason,
            $request,
        ): WorkerPayoutRecord {
            $payout = WorkerPayoutRecord::withoutGlobalScopes()
                ->whereKey($payoutId)
                ->lockForUpdate()
                ->firstOrFail();
            $workspace = Workspace::query()->find($payout->workspace_id);

            if ($workspace === null) {
                throw WorkerPayoutException::periodMismatch();
            }

            $engagement = WorkerEngagement::withoutGlobalScopes()
                ->whereKey($payout->worker_engagement_id)
                ->first();
            $period = WorkerEarningPeriod::withoutGlobalScopes()
                ->whereKey($payout->worker_earning_period_id)
                ->first();
            if (
                $engagement === null
                || $period === null
                || (int) $engagement->workspace_id !== (int) $payout->workspace_id
                || (int) $engagement->worker_profile_id !== (int) $payout->worker_profile_id
                || (int) $period->workspace_id !== (int) $payout->workspace_id
                || (int) $period->worker_engagement_id !== (int) $payout->worker_engagement_id
            ) {
                throw WorkerPayoutException::periodMismatch();
            }

            return $this->workspaceContext->runFor(
                $workspace,
                function () use (
                    $admin,
                    $payout,
                    $next,
                    $providerReference,
                    $reason,
                    $request,
                ): WorkerPayoutRecord {
                    $attributes = $this->transitionAttributes(
                        $next,
                        $providerReference,
                        $reason,
                    );
                    $previous = $payout->status;
                    $payout->transitionTo($next, $attributes);

                    if ($next === WorkerPayoutStatus::Paid) {
                        $this->markPeriodPaid($payout);
                    }

                    $this->auditPlatform(
                        action: 'worker.payout.status_changed',
                        payout: $payout,
                        admin: $admin,
                        request: $request,
                        oldValues: [
                            'status' => $previous->value,
                        ],
                        newValues: array_merge(
                            ['status' => $next->value],
                            array_filter([
                                'provider_reference' => $attributes['provider_reference'] ?? null,
                                'reason' => $attributes['failure_reason'] ?? null,
                            ], static fn (mixed $value): bool => $value !== null),
                        ),
                    );

                    return $payout->refresh();
                },
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function transitionAttributes(
        WorkerPayoutStatus $next,
        ?string $providerReference,
        ?string $reason,
    ): array {
        $reference = $providerReference !== null ? trim($providerReference) : null;
        $normalizedReason = $reason !== null ? trim($reason) : null;

        if ($next === WorkerPayoutStatus::Paid && ($reference === null || $reference === '')) {
            throw WorkerPayoutException::providerReferenceRequired();
        }

        if (
            in_array($next, [WorkerPayoutStatus::Failed, WorkerPayoutStatus::Cancelled], true)
            && ($normalizedReason === null || $normalizedReason === '')
        ) {
            throw WorkerPayoutException::failureReasonRequired();
        }

        return array_filter([
            'provider_reference' => $reference,
            'failure_reason' => in_array(
                $next,
                [WorkerPayoutStatus::Failed, WorkerPayoutStatus::Cancelled],
                true,
            ) ? $normalizedReason : null,
            'processing_at' => $next === WorkerPayoutStatus::Processing ? now() : null,
            'paid_at' => $next === WorkerPayoutStatus::Paid ? now() : null,
            'failed_at' => $next === WorkerPayoutStatus::Failed ? now() : null,
            'cancelled_at' => $next === WorkerPayoutStatus::Cancelled ? now() : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function markPeriodPaid(WorkerPayoutRecord $payout): void
    {
        $period = WorkerEarningPeriod::query()
            ->whereKey($payout->worker_earning_period_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($period->status !== WorkerEarningPeriodStatus::Approved) {
            throw WorkerPayoutException::periodNotApproved();
        }

        $period->transitionTo(WorkerEarningPeriodStatus::Paid, [
            'paid_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function auditPlatform(
        string $action,
        WorkerPayoutRecord $payout,
        PlatformAdmin $admin,
        ?Request $request,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        $this->auditLog->log(
            action: $action,
            entityType: WorkerPayoutRecord::class,
            entityId: $payout->id,
            oldValues: $oldValues,
            newValues: $newValues,
            request: $request,
            meta: [
                'platform_admin_id' => $admin->id,
                'worker_profile_id' => $payout->worker_profile_id,
                'worker_engagement_id' => $payout->worker_engagement_id,
                'worker_earning_period_id' => $payout->worker_earning_period_id,
            ],
            workspaceId: $payout->workspace_id,
        );
    }
}
