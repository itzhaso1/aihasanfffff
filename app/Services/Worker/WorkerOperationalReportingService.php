<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerActivityEventType;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkerWorkSessionStatus;
use App\Enums\WorkforceParticipantType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerActivityEvent;
use App\Models\Worker\WorkerEarningLine;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerPayoutRecord;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Workspace;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class WorkerOperationalReportingService
{
    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 50;

    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function companyOverview(
        User $actor,
        Workspace $workspace,
        array $filters = [],
    ): LengthAwarePaginator {
        $this->assertCompanyAccess($actor, $workspace);

        return $this->workspaceContext->runFor($workspace, function () use ($filters): LengthAwarePaginator {
            $status = WorkerEngagementStatus::tryFrom((string) ($filters['status'] ?? ''));

            $engagements = WorkerEngagement::query()
                ->select([
                    'id',
                    'workspace_id',
                    'worker_profile_id',
                    'status',
                    'job_title',
                    'starts_at',
                    'ends_at',
                ])
                ->with([
                    'workerProfile:id,display_name,profile_photo,availability_status',
                ])
                ->withCount([
                    'workSessions as active_session_count' => function (Builder $query): void {
                        $query->where('status', WorkerWorkSessionStatus::Started->value);
                    },
                    'assignedConversations as handled_conversations_count' => function (Builder $query): void {
                        $query->where('status', '!=', 'archived');
                    },
                    'messages as sent_messages_count' => function (Builder $query): void {
                        $query->where('direction', 'outbound');
                    },
                ])
                ->addSelect([
                    'latest_activity_at' => WorkerActivityEvent::query()
                        ->select('occurred_at')
                        ->whereColumn(
                            'worker_activity_events.worker_engagement_id',
                            'worker_engagements.id',
                        )
                        ->latest('occurred_at')
                        ->latest('id')
                        ->limit(1),
                    'last_handled_at' => Message::query()
                        ->select('created_at')
                        ->whereColumn(
                            'messages.worker_engagement_id',
                            'worker_engagements.id',
                        )
                        ->where('direction', 'outbound')
                        ->latest('created_at')
                        ->latest('id')
                        ->limit(1),
                ])
                ->when(
                    $status instanceof WorkerEngagementStatus,
                    fn (Builder $query): Builder => $query->where('status', $status->value),
                )
                ->orderByDesc('id')
                ->paginate($this->pageSize($filters), [
                    '*',
                ])
                ->withQueryString();

            $engagements->getCollection()->each(
                function (WorkerEngagement $engagement): void {
                    $engagement->setAttribute(
                        'participant_type',
                        WorkforceParticipantType::IndependentWorker->value,
                    );
                    $this->castReportDate($engagement, 'latest_activity_at');
                    $this->castReportDate($engagement, 'last_handled_at');
                },
            );

            return $engagements;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function companyOverviewDetail(
        User $actor,
        Workspace $workspace,
        WorkerEngagement $engagement,
        array $filters = [],
    ): array {
        $this->assertCompanyAccess($actor, $workspace);
        $this->assertCompanyEngagement($workspace, $engagement);

        return $this->workspaceContext->runFor(
            $workspace,
            fn (): array => $this->detailPayload(
                $this->loadCompanyEngagement($engagement),
                $filters,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function workerEngagements(
        User $worker,
        array $filters = [],
    ): LengthAwarePaginator {
        $status = WorkerEngagementStatus::tryFrom((string) ($filters['status'] ?? ''));

        return WorkerEngagement::withoutGlobalScopes()
            ->select([
                'id',
                'workspace_id',
                'worker_profile_id',
                'status',
                'job_title',
                'starts_at',
                'ends_at',
            ])
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            })
            ->with([
                'workspace:id,name',
                'workerProfile:id,display_name,availability_status',
            ])
            ->when(
                $status instanceof WorkerEngagementStatus,
                fn (Builder $query): Builder => $query->where('status', $status->value),
            )
            ->orderByDesc('id')
            ->paginate($this->pageSize($filters))
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function companySessionReport(
        User $actor,
        Workspace $workspace,
        WorkerEngagement $engagement,
        array $filters = [],
    ): array {
        $this->assertCompanyAccess($actor, $workspace);
        $this->assertCompanyEngagement($workspace, $engagement);

        return $this->workspaceContext->runFor($workspace, function () use (
            $engagement,
            $filters,
        ): array {
            $scopedEngagement = $this->loadCompanyEngagement($engagement);

            return $this->sessionPayload($scopedEngagement, $filters);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function companyEarningReport(
        User $actor,
        Workspace $workspace,
        WorkerEngagement $engagement,
        array $filters = [],
    ): array {
        $this->assertCompanyAccess($actor, $workspace);
        $this->assertCompanyEngagement($workspace, $engagement);

        return $this->workspaceContext->runFor($workspace, function () use (
            $engagement,
            $filters,
        ): array {
            $scopedEngagement = $this->loadCompanyEngagement($engagement);

            return $this->earningPayload($scopedEngagement, $filters);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function workerOverview(
        User $worker,
        int $engagementId,
        array $filters = [],
    ): array {
        [$engagement, $workspace] = $this->ownedWorkerEngagement($worker, $engagementId);

        return $this->workspaceContext->runFor(
            $workspace,
            fn (): array => $this->detailPayload($engagement, $filters),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function workerSessionReport(
        User $worker,
        int $engagementId,
        array $filters = [],
    ): array {
        [$engagement, $workspace] = $this->ownedWorkerEngagement($worker, $engagementId);

        return $this->workspaceContext->runFor(
            $workspace,
            fn (): array => $this->sessionPayload($engagement, $filters),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function workerEarningReport(
        User $worker,
        int $engagementId,
        array $filters = [],
    ): array {
        [$engagement, $workspace] = $this->ownedWorkerEngagement($worker, $engagementId);

        return $this->workspaceContext->runFor(
            $workspace,
            fn (): array => $this->earningPayload($engagement, $filters),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function platformOverview(
        PlatformAdmin $admin,
        array $filters = [],
    ): array {
        if (! auth('platform_admin')->check()
            || (int) auth('platform_admin')->id() !== (int) $admin->id) {
            throw new AuthorizationException('Platform admin authentication is required.');
        }

        $status = WorkerEngagementStatus::tryFrom((string) ($filters['status'] ?? ''));

        $engagementQuery = WorkerEngagement::query()
            ->select([
                'id',
                'workspace_id',
                'worker_profile_id',
                'status',
                'job_title',
                'starts_at',
                'ends_at',
            ])
            ->with([
                'workerProfile:id,display_name,verification_status,lifecycle_status',
                'workspace:id,name',
            ])
            ->when(
                $status instanceof WorkerEngagementStatus,
                fn (Builder $query): Builder => $query->where('status', $status->value),
            )
            ->orderByDesc('id');

        $engagements = $engagementQuery
            ->paginate($this->pageSize($filters))
            ->withQueryString();

        return [
            'metrics' => [
                'verified_workers' => WorkerProfile::query()
                    ->where('verification_status', WorkerProfile::VERIFICATION_APPROVED)
                    ->where('lifecycle_status', WorkerProfile::LIFECYCLE_VERIFIED)
                    ->count(),
                'discoverable_workers' => WorkerProfile::query()
                    ->where('verification_status', WorkerProfile::VERIFICATION_APPROVED)
                    ->where('lifecycle_status', WorkerProfile::LIFECYCLE_VERIFIED)
                    ->whereHas('discoverySettings', function (Builder $query): void {
                        $query->where('is_discoverable', true);
                    })
                    ->count(),
                'active_engagements' => WorkerEngagement::query()
                    ->where('status', WorkerEngagementStatus::Active->value)
                    ->count(),
                'pending_offers' => WorkerEngagement::query()
                    ->where('status', WorkerEngagementStatus::Offered->value)
                    ->count(),
                'active_workers' => WorkerEngagement::query()
                    ->where('status', WorkerEngagementStatus::Active->value)
                    ->distinct()
                    ->count('worker_profile_id'),
                'suspended_workers' => WorkerProfile::query()
                    ->where(function (Builder $query): void {
                        $query
                            ->where('verification_status', WorkerProfile::VERIFICATION_SUSPENDED)
                            ->orWhere('lifecycle_status', WorkerProfile::LIFECYCLE_SUSPENDED);
                    })
                    ->count(),
            ],
            'payoutStatuses' => $this->payoutStatusSummary(),
            'engagements' => $engagements,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function detailPayload(WorkerEngagement $engagement, array $filters): array
    {
        return [
            'engagement' => $engagement,
            'participantType' => WorkforceParticipantType::IndependentWorker->value,
            'operationalSummary' => [
                'currentSession' => $this->currentSession($engagement->id),
                'sessions' => $this->sessionSummary($engagement->id, $filters),
                'inbox' => $this->inboxSummary($engagement->id),
                'earnings' => $this->earningsSummary($engagement->id),
                'payouts' => $this->payoutSummary($engagement->id),
                'capabilities' => $this->grantedCapabilities($engagement->id),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function sessionPayload(WorkerEngagement $engagement, array $filters): array
    {
        return [
            'engagement' => $engagement,
            'participantType' => WorkforceParticipantType::IndependentWorker->value,
            'currentSession' => $this->currentSession($engagement->id),
            'sessionSummary' => $this->sessionSummary($engagement->id, $filters),
            'sessions' => $this->sessionQuery($engagement->id, $filters)
                ->paginate($this->pageSize($filters), ['*'], 'sessions_page')
                ->withQueryString(),
            'activities' => $this->activityQuery($engagement->id, $filters)
                ->paginate($this->pageSize($filters), ['*'], 'activity_page')
                ->withQueryString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function earningPayload(WorkerEngagement $engagement, array $filters): array
    {
        return [
            'engagement' => $engagement,
            'participantType' => WorkforceParticipantType::IndependentWorker->value,
            'earningsSummary' => $this->earningsSummary($engagement->id),
            'payoutSummary' => $this->payoutSummary($engagement->id),
            'periods' => $this->periodQuery($engagement->id)
                ->paginate($this->pageSize($filters), ['*'], 'periods_page')
                ->withQueryString(),
            'payouts' => $this->payoutQuery($engagement->id)
                ->paginate($this->pageSize($filters), ['*'], 'payouts_page')
                ->withQueryString(),
        ];
    }

    private function loadCompanyEngagement(WorkerEngagement $engagement): WorkerEngagement
    {
        return WorkerEngagement::query()
            ->select([
                'id',
                'workspace_id',
                'worker_profile_id',
                'status',
                'job_title',
                'starts_at',
                'ends_at',
            ])
            ->with([
                'workerProfile:id,display_name,profile_photo,country_code,nationality,availability_status,verification_status,lifecycle_status,professional_summary',
            ])
            ->whereKey($engagement->id)
            ->firstOrFail();
    }

    /**
     * @return array{0: WorkerEngagement, 1: Workspace}
     */
    private function ownedWorkerEngagement(User $worker, int $engagementId): array
    {
        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->whereKey($engagementId)
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            })
            ->with([
                'workspace',
                'workerProfile:id,user_id,display_name,profile_photo,country_code,availability_status,verification_status,lifecycle_status,professional_summary',
            ])
            ->first();

        if (! $engagement instanceof WorkerEngagement
            || ! $engagement->workspace instanceof Workspace) {
            throw $this->notFound($engagementId);
        }

        return [$engagement, $engagement->workspace];
    }

    private function assertCompanyAccess(User $actor, Workspace $workspace): void
    {
        if (
            (int) $this->workspaceContext->workspaceId() !== (int) $workspace->id
            || ! $this->workspaceAccess->isActiveMember($actor, $workspace)
        ) {
            throw new AuthorizationException('You are not authorized to view worker operations.');
        }
    }

    private function assertCompanyEngagement(
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): void {
        if ((int) $engagement->workspace_id !== (int) $workspace->id) {
            throw $this->notFound($engagement->id);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function sessionQuery(int $engagementId, array $filters): Builder
    {
        [$from, $to] = $this->dateRange($filters);

        return WorkerWorkSession::query()
            ->select([
                'id',
                'workspace_id',
                'worker_engagement_id',
                'status',
                'started_at',
                'ended_at',
                'duration_seconds',
            ])
            ->where('worker_engagement_id', $engagementId)
            ->when($from !== null, fn (Builder $query): Builder => $query->where('started_at', '>=', $from))
            ->when($to !== null, fn (Builder $query): Builder => $query->where('started_at', '<=', $to))
            ->with([
                'segments' => function (HasMany $query): void {
                    $query
                        ->select([
                            'id',
                            'workspace_id',
                            'worker_work_session_id',
                            'segment_type',
                            'started_at',
                            'ended_at',
                            'duration_seconds',
                        ])
                        ->orderBy('started_at')
                        ->orderBy('id');
                },
                'activityEvents' => function (HasMany $query): void {
                    $query
                        ->select([
                            'id',
                            'workspace_id',
                            'worker_engagement_id',
                            'worker_profile_id',
                            'worker_work_session_id',
                            'event_type',
                            'occurred_at',
                        ])
                        ->orderBy('occurred_at')
                        ->orderBy('id');
                },
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function activityQuery(int $engagementId, array $filters): Builder
    {
        [$from, $to] = $this->dateRange($filters);
        $eventType = WorkerActivityEventType::tryFrom((string) ($filters['event_type'] ?? ''));
        $sessionId = $this->positiveInteger($filters['session_id'] ?? null);

        return WorkerActivityEvent::query()
            ->select([
                'id',
                'workspace_id',
                'worker_engagement_id',
                'worker_profile_id',
                'worker_work_session_id',
                'event_type',
                'subject_type',
                'subject_id',
                'occurred_at',
                'payload',
            ])
            ->where('worker_engagement_id', $engagementId)
            ->when($from !== null, fn (Builder $query): Builder => $query->where('occurred_at', '>=', $from))
            ->when($to !== null, fn (Builder $query): Builder => $query->where('occurred_at', '<=', $to))
            ->when(
                $eventType instanceof WorkerActivityEventType,
                fn (Builder $query): Builder => $query->where('event_type', $eventType->value),
            )
            ->when(
                $sessionId !== null,
                fn (Builder $query): Builder => $query->where('worker_work_session_id', $sessionId),
            )
            ->with('session:id,workspace_id,started_at,ended_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    private function currentSession(int $engagementId): ?WorkerWorkSession
    {
        return WorkerWorkSession::query()
            ->where('worker_engagement_id', $engagementId)
            ->where('status', WorkerWorkSessionStatus::Started->value)
            ->with([
                'segments' => fn (HasMany $query): HasMany => $query
                    ->whereNull('ended_at')
                    ->latest('started_at')
                    ->latest('id'),
            ])
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     count:int,
     *     recorded_duration_seconds:int,
     *     active:bool,
     *     last_started_at:?CarbonInterface,
     *     last_ended_at:?CarbonInterface
     * }
     */
    private function sessionSummary(int $engagementId, array $filters): array
    {
        [$from, $to] = $this->dateRange($filters);
        $aggregate = WorkerWorkSession::query()
            ->where('worker_engagement_id', $engagementId)
            ->when($from !== null, fn (Builder $query): Builder => $query->where('started_at', '>=', $from))
            ->when($to !== null, fn (Builder $query): Builder => $query->where('started_at', '<=', $to))
            ->selectRaw('COUNT(*) as session_count, COALESCE(SUM(duration_seconds), 0) as recorded_duration_seconds')
            ->first();
        $lastSession = WorkerWorkSession::query()
            ->where('worker_engagement_id', $engagementId)
            ->latest('started_at')
            ->latest('id')
            ->first(['started_at', 'ended_at']);

        return [
            'count' => (int) ($aggregate?->session_count ?? 0),
            'recorded_duration_seconds' => (int) ($aggregate?->recorded_duration_seconds ?? 0),
            'active' => $this->currentSession($engagementId) !== null,
            'last_started_at' => $lastSession?->started_at,
            'last_ended_at' => $lastSession?->ended_at,
        ];
    }

    /**
     * @return array{
     *     assigned_conversations_count:int,
     *     open_assigned_conversations_count:int,
     *     sent_messages_count:int,
     *     last_handled_at:?CarbonInterface
     * }
     */
    private function inboxSummary(int $engagementId): array
    {
        $conversationQuery = Conversation::query()
            ->where('assigned_worker_engagement_id', $engagementId);
        $messagesQuery = Message::query()
            ->where('worker_engagement_id', $engagementId)
            ->where('direction', 'outbound');
        $lastHandledAt = $messagesQuery->max('created_at');

        return [
            'assigned_conversations_count' => (int) (clone $conversationQuery)->count(),
            'open_assigned_conversations_count' => (int) (clone $conversationQuery)
                ->where('status', 'open')
                ->count(),
            'sent_messages_count' => (int) (clone $messagesQuery)->count(),
            'last_handled_at' => $lastHandledAt !== null
                ? CarbonImmutable::parse((string) $lastHandledAt)
                : null,
        ];
    }

    /**
     * @return Collection<int, WorkerEngagementPermission>
     */
    private function grantedCapabilities(int $engagementId): Collection
    {
        return WorkerEngagementPermission::query()
            ->select(['id', 'worker_engagement_id', 'capability_key'])
            ->where('worker_engagement_id', $engagementId)
            ->where('allowed', true)
            ->with('capabilityDefinition:id,capability_key,display_name,description')
            ->orderBy('capability_key')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function earningsSummary(int $engagementId): array
    {
        $periodStatuses = WorkerEarningPeriod::query()
            ->where('worker_engagement_id', $engagementId)
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn (WorkerEarningPeriod $period): array => [
                (string) $period->getRawOriginal('status') => (int) $period->total,
            ])
            ->all();

        $lineTotals = WorkerEarningLine::query()
            ->join(
                'worker_earning_periods as report_periods',
                'report_periods.id',
                '=',
                'worker_earning_lines.worker_earning_period_id',
            )
            ->where('worker_earning_lines.worker_engagement_id', $engagementId)
            ->where(
                'report_periods.workspace_id',
                (int) $this->workspaceContext->workspaceId(),
            )
            ->selectRaw(
                'report_periods.status as period_status, worker_earning_lines.currency, SUM(worker_earning_lines.amount_minor) as amount_minor',
            )
            ->groupBy('report_periods.status', 'worker_earning_lines.currency')
            ->orderBy('report_periods.status')
            ->orderBy('worker_earning_lines.currency')
            ->get()
            ->map(fn (object $row): array => [
                'period_status' => (string) $row->period_status,
                'currency' => (string) $row->currency,
                'amount_minor' => (int) $row->amount_minor,
            ])
            ->all();

        $latestPeriod = WorkerEarningPeriod::query()
            ->where('worker_engagement_id', $engagementId)
            ->latest('starts_at')
            ->latest('id')
            ->first([
                'id',
                'status',
                'starts_at',
                'ends_at',
            ]);

        return [
            'period_statuses' => $periodStatuses,
            'line_totals' => $lineTotals,
            'latest_period' => $latestPeriod,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutSummary(int $engagementId): array
    {
        $statusCounts = WorkerPayoutRecord::query()
            ->where('worker_engagement_id', $engagementId)
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn (WorkerPayoutRecord $payout): array => [
                (string) $payout->getRawOriginal('status') => (int) $payout->total,
            ])
            ->all();

        $totals = WorkerPayoutRecord::query()
            ->where('worker_engagement_id', $engagementId)
            ->select('status', 'currency')
            ->selectRaw('SUM(amount_minor) as amount_minor')
            ->groupBy('status', 'currency')
            ->orderBy('status')
            ->orderBy('currency')
            ->get()
            ->map(fn (WorkerPayoutRecord $payout): array => [
                'status' => (string) $payout->getRawOriginal('status'),
                'currency' => (string) $payout->currency,
                'amount_minor' => (int) $payout->amount_minor,
            ])
            ->all();

        return [
            'status_counts' => $statusCounts,
            'totals' => $totals,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function payoutStatusSummary(): array
    {
        return WorkerPayoutRecord::query()
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn (WorkerPayoutRecord $payout): array => [
                (string) $payout->getRawOriginal('status') => (int) $payout->total,
            ])
            ->all();
    }

    private function periodQuery(int $engagementId): Builder
    {
        return WorkerEarningPeriod::query()
            ->select([
                'id',
                'workspace_id',
                'worker_engagement_id',
                'status',
                'starts_at',
                'ends_at',
                'approved_at',
                'paid_at',
            ])
            ->where('worker_engagement_id', $engagementId)
            ->with([
                'earningLines' => function (HasMany $query): void {
                    $query
                        ->select([
                            'id',
                            'workspace_id',
                            'worker_engagement_id',
                            'worker_earning_period_id',
                            'line_type',
                            'source_type',
                            'amount_minor',
                            'currency',
                            'description',
                        ])
                        ->orderBy('id');
                },
            ])
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }

    private function payoutQuery(int $engagementId): Builder
    {
        return WorkerPayoutRecord::query()
            ->select([
                'id',
                'workspace_id',
                'worker_profile_id',
                'worker_engagement_id',
                'worker_earning_period_id',
                'amount_minor',
                'currency',
                'status',
                'requested_at',
                'processing_at',
                'paid_at',
                'failed_at',
                'cancelled_at',
            ])
            ->where('worker_engagement_id', $engagementId)
            ->with([
                'period:id,workspace_id,worker_engagement_id,status,starts_at,ends_at',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function dateRange(array $filters): array
    {
        $from = $this->dateValue($filters['from'] ?? null, false);
        $to = $this->dateValue($filters['to'] ?? null, true);

        return [$from, $to];
    }

    private function dateValue(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', trim($value));

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function pageSize(array $filters): int
    {
        $pageSize = filter_var($filters['per_page'] ?? self::DEFAULT_PAGE_SIZE, FILTER_VALIDATE_INT);

        if ($pageSize === false) {
            return self::DEFAULT_PAGE_SIZE;
        }

        return min(self::MAX_PAGE_SIZE, max(1, (int) $pageSize));
    }

    private function positiveInteger(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $parsed === false ? null : (int) $parsed;
    }

    private function castReportDate(WorkerEngagement $engagement, string $attribute): void
    {
        $value = $engagement->getAttribute($attribute);

        if ($value !== null && ! $value instanceof CarbonImmutable) {
            $engagement->setAttribute($attribute, CarbonImmutable::parse((string) $value));
        }
    }

    private function notFound(int $id): ModelNotFoundException
    {
        return (new ModelNotFoundException)->setModel(WorkerEngagement::class, [$id]);
    }
}
