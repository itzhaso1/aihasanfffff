<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;

class AuditLogService
{
    public function __construct(private readonly WorkspaceContext $workspaceContext) {}

    public function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null,
        ?Request $request = null,
        ?array $meta = null,
        ?int $workspaceId = null,
    ): AuditLog {
        return AuditLog::withoutEvents(function () use (
            $action,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $actor,
            $request,
            $meta,
            $workspaceId,
        ): AuditLog {
            return AuditLog::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId ?? $this->workspaceContext->workspaceId(),
                'user_id' => $actor?->id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'meta' => $meta,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'occurred_at' => now(),
            ]);
        });
    }
}
