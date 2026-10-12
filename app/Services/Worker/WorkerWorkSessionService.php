<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerActivityEventType;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkerWorkSessionSegmentType;
use App\Enums\WorkerWorkSessionStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Worker\WorkerWorkSessionSegment;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkerWorkSessionService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly WorkerActivityEventService $activityEvents,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @return array{
     *     engagement: WorkerEngagement,
     *     sessions: Collection<int, WorkerWorkSession>
     * }
     */
    public function forWorker(User $worker, int $engagementId): array
    {
        $engagement = $this->ownedEngagement($worker, $engagementId);
        $workspace = $engagement->workspace;

        if ($workspace === null) {
            throw WorkerWorkSessionException::workspaceMismatch();
        }

        $sessions = $this->workspaceContext->runFor(
            $workspace,
            fn (): Collection => $this->sessionsForEngagement($engagement),
        );

        return [
            'engagement' => $engagement,
            'sessions' => $sessions,
        ];
    }

    /**
     * @return Collection<int, WorkerWorkSession>
     */
    public function forCompany(WorkerEngagement $engagement): Collection
    {
        if ((int) $this->workspaceContext->workspaceId() !== (int) $engagement->workspace_id) {
            throw WorkerWorkSessionException::workspaceMismatch();
        }

        return $this->sessionsForEngagement($engagement);
    }

    public function start(
        User $worker,
        int $engagementId,
        ?Request $request = null,
    ): WorkerWorkSession {
        return DB::transaction(function () use ($worker, $engagementId, $request): WorkerWorkSession {
            $engagement = $this->ownedEngagement($worker, $engagementId, lock: true);
            $this->assertCanStart($engagement);
            $workspace = $engagement->workspace;

            if ($workspace === null) {
                throw WorkerWorkSessionException::workspaceMismatch();
            }

            return $this->workspaceContext->runFor($workspace, function () use (
                $worker,
                $engagement,
                $request,
            ): WorkerWorkSession {
                $activeExists = WorkerWorkSession::query()
                    ->where('worker_engagement_id', $engagement->id)
                    ->where('status', WorkerWorkSessionStatus::Started->value)
                    ->lockForUpdate()
                    ->exists();

                if ($activeExists) {
                    throw WorkerWorkSessionException::activeSessionExists();
                }

                $startedAt = now();
                $session = WorkerWorkSession::query()->create([
                    'workspace_id' => $engagement->workspace_id,
                    'worker_engagement_id' => $engagement->id,
                    'status' => WorkerWorkSessionStatus::Started,
                    'active_session_key' => "worker-engagement:{$engagement->id}",
                    'started_at' => $startedAt,
                    'started_by_user_id' => $worker->id,
                ]);

                WorkerWorkSessionSegment::query()->create([
                    'workspace_id' => $engagement->workspace_id,
                    'worker_work_session_id' => $session->id,
                    'segment_type' => WorkerWorkSessionSegmentType::Active,
                    'started_at' => $startedAt,
                ]);

                $event = $this->activityEvents->recordSessionEvent(
                    $session->load('engagement.workerProfile'),
                    $worker,
                    WorkerActivityEventType::SessionStarted,
                    $request,
                );

                $this->auditSession(
                    action: 'worker.work_session.started',
                    session: $session,
                    actor: $worker,
                    request: $request,
                    meta: ['activity_event_id' => $event->id],
                );

                return $session->load(['segments', 'activityEvents']);
            });
        });
    }

    public function end(
        User $worker,
        int $engagementId,
        ?Request $request = null,
    ): WorkerWorkSession {
        return DB::transaction(function () use ($worker, $engagementId, $request): WorkerWorkSession {
            $engagement = $this->ownedEngagement($worker, $engagementId, lock: true);
            $this->assertCanEnd($engagement);
            $workspace = $engagement->workspace;

            if ($workspace === null) {
                throw WorkerWorkSessionException::workspaceMismatch();
            }

            return $this->workspaceContext->runFor($workspace, function () use (
                $worker,
                $engagement,
                $request,
            ): WorkerWorkSession {
                $session = $this->activeSession($engagement);
                $endedAt = now();
                $openSegment = $this->openSegment($session);
                $openSegment->closeAt($endedAt);

                $session->forceFill([
                    'ended_at' => $endedAt,
                    'duration_seconds' => $this->durationSeconds($session->started_at, $endedAt),
                    'active_session_key' => null,
                    'ended_by_user_id' => $worker->id,
                ]);
                $session->transitionTo(WorkerWorkSessionStatus::Ended);

                $event = $this->activityEvents->recordSessionEvent(
                    $session->load('engagement.workerProfile'),
                    $worker,
                    WorkerActivityEventType::SessionEnded,
                    $request,
                );

                $this->auditSession(
                    action: 'worker.work_session.ended',
                    session: $session,
                    actor: $worker,
                    request: $request,
                    meta: [
                        'activity_event_id' => $event->id,
                        'duration_seconds' => $session->duration_seconds,
                    ],
                );

                return $session->load(['segments', 'activityEvents']);
            });
        });
    }

    public function beginBreak(
        User $worker,
        int $engagementId,
        ?Request $request = null,
    ): WorkerWorkSession {
        return $this->switchSegment(
            $worker,
            $engagementId,
            WorkerWorkSessionSegmentType::Break,
            $request,
        );
    }

    public function resume(
        User $worker,
        int $engagementId,
        ?Request $request = null,
    ): WorkerWorkSession {
        return $this->switchSegment(
            $worker,
            $engagementId,
            WorkerWorkSessionSegmentType::Active,
            $request,
        );
    }

    private function switchSegment(
        User $worker,
        int $engagementId,
        WorkerWorkSessionSegmentType $nextType,
        ?Request $request,
    ): WorkerWorkSession {
        return DB::transaction(function () use (
            $worker,
            $engagementId,
            $nextType,
            $request,
        ): WorkerWorkSession {
            $engagement = $this->ownedEngagement($worker, $engagementId, lock: true);
            $this->assertCanChangeSegment($engagement);
            $workspace = $engagement->workspace;

            if ($workspace === null) {
                throw WorkerWorkSessionException::workspaceMismatch();
            }

            return $this->workspaceContext->runFor($workspace, function () use (
                $worker,
                $engagement,
                $nextType,
                $request,
            ): WorkerWorkSession {
                $session = $this->activeSession($engagement);
                $currentSegment = $this->openSegment($session);

                if (
                    ! $currentSegment->segment_type instanceof WorkerWorkSessionSegmentType
                    || ! $nextType->canFollow($currentSegment->segment_type)
                ) {
                    throw WorkerWorkSessionException::invalidSegmentTransition(
                        $currentSegment->segment_type instanceof WorkerWorkSessionSegmentType
                            ? $currentSegment->segment_type
                            : WorkerWorkSessionSegmentType::Active,
                        $nextType,
                    );
                }

                $startedAt = now();
                $currentSegment->closeAt($startedAt);
                $nextSegment = WorkerWorkSessionSegment::query()->create([
                    'workspace_id' => $engagement->workspace_id,
                    'worker_work_session_id' => $session->id,
                    'segment_type' => $nextType,
                    'started_at' => $startedAt,
                ]);

                $this->auditLog->log(
                    action: 'worker.work_session.segment_started',
                    entityType: WorkerWorkSessionSegment::class,
                    entityId: $nextSegment->id,
                    newValues: [
                        'segment_type' => $nextType->value,
                        'started_at' => $startedAt->toIso8601String(),
                    ],
                    actor: $worker,
                    request: $request,
                    meta: [
                        'worker_engagement_id' => $engagement->id,
                        'worker_work_session_id' => $session->id,
                        'previous_segment_id' => $currentSegment->id,
                    ],
                    workspaceId: $engagement->workspace_id,
                );

                return $session->load(['segments', 'activityEvents']);
            });
        });
    }

    private function sessionsForEngagement(WorkerEngagement $engagement): Collection
    {
        return WorkerWorkSession::query()
            ->where('worker_engagement_id', $engagement->id)
            ->with([
                'segments' => fn (HasMany $query): HasMany => $query
                    ->orderBy('started_at')
                    ->orderBy('id'),
                'activityEvents' => fn (HasMany $query): HasMany => $query
                    ->orderBy('occurred_at')
                    ->orderBy('id'),
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }

    private function activeSession(WorkerEngagement $engagement): WorkerWorkSession
    {
        $session = WorkerWorkSession::query()
            ->where('worker_engagement_id', $engagement->id)
            ->where('status', WorkerWorkSessionStatus::Started->value)
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            throw WorkerWorkSessionException::noActiveSession();
        }

        return $session;
    }

    private function openSegment(WorkerWorkSession $session): WorkerWorkSessionSegment
    {
        $segments = WorkerWorkSessionSegment::query()
            ->where('worker_work_session_id', $session->id)
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        if ($segments->isEmpty()) {
            throw WorkerWorkSessionException::segmentNotOpen();
        }

        if ($segments->count() > 1) {
            throw WorkerWorkSessionException::overlappingSegments();
        }

        return $segments->firstOrFail();
    }

    private function assertCanStart(WorkerEngagement $engagement): void
    {
        if ($engagement->status !== WorkerEngagementStatus::Active) {
            throw WorkerWorkSessionException::engagementNotActive();
        }

        $workerProfile = $engagement->workerProfile;
        if (
            ! $workerProfile instanceof WorkerProfile
            || $workerProfile->verification_status !== WorkerProfile::VERIFICATION_APPROVED
            || $workerProfile->lifecycle_status !== WorkerProfile::LIFECYCLE_VERIFIED
        ) {
            throw WorkerWorkSessionException::workerNotEligible();
        }
    }

    private function assertCanChangeSegment(WorkerEngagement $engagement): void
    {
        $this->assertCanStart($engagement);
    }

    private function assertCanEnd(WorkerEngagement $engagement): void
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
            throw WorkerWorkSessionException::engagementCannotEnd();
        }
    }

    private function ownedEngagement(
        User $worker,
        int $engagementId,
        bool $lock = false,
    ): WorkerEngagement {
        $query = WorkerEngagement::withoutGlobalScopes()
            ->with(['workspace', 'workerProfile'])
            ->whereKey($engagementId)
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function durationSeconds(
        CarbonInterface $startedAt,
        CarbonInterface $endedAt,
    ): int {
        return max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function auditSession(
        string $action,
        WorkerWorkSession $session,
        User $actor,
        ?Request $request,
        array $meta = [],
    ): void {
        $this->auditLog->log(
            action: $action,
            entityType: WorkerWorkSession::class,
            entityId: $session->id,
            oldValues: null,
            newValues: [
                'status' => $session->status->value,
                'started_at' => $session->started_at?->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
            ],
            actor: $actor,
            request: $request,
            meta: array_merge($meta, [
                'worker_engagement_id' => $session->worker_engagement_id,
                'worker_work_session_id' => $session->id,
            ]),
            workspaceId: $session->workspace_id,
        );
    }
}
