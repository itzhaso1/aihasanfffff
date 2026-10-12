<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class WorkspaceAuditObserver
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function created(Model $model): void
    {
        $this->auditLogService->log(
            action: $this->domainAction($model, 'created'),
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: null,
            newValues: $this->safeAttributes($model),
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $this->resolveWorkspaceId($model),
        );
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        if ($changes === []) {
            return;
        }

        $oldValues = collect($changes)
            ->keys()
            ->mapWithKeys(fn (string $key): array => [$key => $model->getOriginal($key)])
            ->all();

        $this->auditLogService->log(
            action: $this->domainAction($model, 'updated'),
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: $oldValues,
            newValues: $changes,
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $this->resolveWorkspaceId($model),
        );
    }

    public function deleted(Model $model): void
    {
        $this->auditLogService->log(
            action: $this->domainAction($model, 'deleted'),
            entityType: $model::class,
            entityId: $model->getKey(),
            oldValues: $this->safeAttributes($model),
            newValues: null,
            actor: Auth::user() instanceof User ? Auth::user() : null,
            workspaceId: $this->resolveWorkspaceId($model),
        );
    }

    private function domainAction(Model $model, string $event): string
    {
        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeAttributes(Model $model): array
    {
        return collect($model->attributesToArray())
            ->except(['password', 'remember_token', 'token', 'provider_payload'])
            ->all();
    }

    private function resolveWorkspaceId(Model $model): ?int
    {
        $workspaceId = $model->getAttribute('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }
}
