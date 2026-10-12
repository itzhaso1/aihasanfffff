<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerActivityEventType;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkerWorkSessionStatus;
use App\Models\User;
use App\Models\Worker\WorkerActivityEvent;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerWorkSession;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;

final class WorkerActivityEventService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function recordSessionEvent(
        WorkerWorkSession $session,
        User $actor,
        WorkerActivityEventType $eventType,
        ?Request $request = null,
    ): WorkerActivityEvent {
        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->with(['workerProfile', 'workspace'])
            ->whereKey($session->worker_engagement_id)
            ->where('workspace_id', $session->workspace_id)
            ->first();
        $workerProfile = $engagement?->workerProfile;

        if (
            $engagement === null
            || $workerProfile === null
            || (int) $session->workspace_id !== (int) $engagement->workspace_id
            || (int) $workerProfile->id !== (int) $engagement->worker_profile_id
            || (int) $workerProfile->user_id !== (int) $actor->id
        ) {
            throw WorkerActivityEventException::inconsistentAttribution();
        }

        $expectedStatus = match ($eventType) {
            WorkerActivityEventType::SessionStarted => WorkerWorkSessionStatus::Started,
            WorkerActivityEventType::SessionEnded => WorkerWorkSessionStatus::Ended,
            WorkerActivityEventType::MessageSent,
            WorkerActivityEventType::ConversationAssigned => throw WorkerActivityEventException::invalidSessionEvent($eventType),
        };

        if ($session->status !== $expectedStatus) {
            throw WorkerActivityEventException::invalidSessionEvent($eventType);
        }

        $allowedEngagementStatuses = match ($eventType) {
            WorkerActivityEventType::SessionStarted => [WorkerEngagementStatus::Active],
            WorkerActivityEventType::SessionEnded => [
                WorkerEngagementStatus::Active,
                WorkerEngagementStatus::Paused,
                WorkerEngagementStatus::Completed,
                WorkerEngagementStatus::Terminated,
            ],
        };

        if (
            ! $engagement->status instanceof WorkerEngagementStatus
            || ! in_array($engagement->status, $allowedEngagementStatuses, true)
        ) {
            throw WorkerActivityEventException::invalidSessionEvent($eventType);
        }

        $workspace = $engagement->workspace;
        if ($workspace === null) {
            throw WorkerActivityEventException::inconsistentAttribution();
        }

        return $this->workspaceContext->runFor($workspace, function () use (
            $session,
            $actor,
            $eventType,
            $request,
            $engagement,
            $workerProfile,
        ): WorkerActivityEvent {
            $occurredAt = now();
            $event = WorkerActivityEvent::query()->create([
                'workspace_id' => $session->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'worker_profile_id' => $workerProfile->id,
                'actor_user_id' => $actor->id,
                'worker_work_session_id' => $session->id,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'payload' => [
                    'session_status' => $session->status->value,
                ],
            ]);

            $this->auditLog->log(
                action: 'worker.activity_event.recorded',
                entityType: WorkerActivityEvent::class,
                entityId: $event->id,
                newValues: [
                    'event_type' => $eventType->value,
                    'occurred_at' => $occurredAt->toIso8601String(),
                ],
                actor: $actor,
                request: $request,
                meta: [
                    'worker_profile_id' => $workerProfile->id,
                    'worker_engagement_id' => $engagement->id,
                    'worker_work_session_id' => $session->id,
                    'event_type' => $eventType->value,
                ],
                workspaceId: $session->workspace_id,
            );

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordOperationalEvent(
        WorkerEngagement $engagement,
        User $worker,
        WorkerActivityEventType $eventType,
        ?string $subjectType,
        ?int $subjectId,
        array $payload = [],
        ?Request $request = null,
    ): WorkerActivityEvent {
        if (! in_array($eventType, [
            WorkerActivityEventType::MessageSent,
            WorkerActivityEventType::ConversationAssigned,
        ], true)) {
            throw WorkerActivityEventException::invalidSessionEvent($eventType);
        }

        $engagement->loadMissing(['workerProfile', 'workspace']);
        $workerProfile = $engagement->workerProfile;
        $workspace = $engagement->workspace;

        if (
            $workerProfile === null
            || $workspace === null
            || (int) $workerProfile->user_id !== (int) $worker->id
            || (int) $engagement->workspace_id !== (int) $workspace->id
        ) {
            throw WorkerActivityEventException::inconsistentAttribution();
        }

        return $this->workspaceContext->runFor($workspace, function () use (
            $engagement,
            $worker,
            $eventType,
            $subjectType,
            $subjectId,
            $payload,
            $request,
            $workerProfile,
        ): WorkerActivityEvent {
            $occurredAt = now();
            $event = WorkerActivityEvent::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'worker_profile_id' => $workerProfile->id,
                'actor_user_id' => $worker->id,
                'event_type' => $eventType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'occurred_at' => $occurredAt,
                'payload' => $payload,
            ]);

            $this->auditLog->log(
                action: 'worker.activity_event.recorded',
                entityType: WorkerActivityEvent::class,
                entityId: $event->id,
                newValues: [
                    'event_type' => $eventType->value,
                    'occurred_at' => $occurredAt->toIso8601String(),
                ],
                actor: $worker,
                request: $request,
                meta: [
                    'worker_profile_id' => $workerProfile->id,
                    'worker_engagement_id' => $engagement->id,
                    'event_type' => $eventType->value,
                ],
                workspaceId: $engagement->workspace_id,
            );

            return $event;
        });
    }
}
