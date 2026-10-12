<?php

namespace App\Models;

use App\Exceptions\MissingWorkspaceContextException;
use App\Services\Workspace\WorkspaceResolverService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

abstract class WorkspaceScopedModel extends Model
{
    public function resolveRouteBinding($value, $field = null)
    {
        $this->ensureWorkspaceContextForBinding();

        $context = app(WorkspaceContext::class);
        $workspaceId = $context->workspaceId();
        $user = $this->bindingUser();

        if ($user && $workspaceId === null && ! $context->isBypassingTenantScope() && ! $this->shouldBindWithoutTenantScope()) {
            return null;
        }

        if ($this->shouldBindWithoutTenantScope()) {
            return static::withoutGlobalScope('workspace')
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function resolveChildRouteBinding($childType, $value, $field)
    {
        $this->ensureWorkspaceContextForBinding();

        $context = app(WorkspaceContext::class);
        $workspaceId = $context->workspaceId();
        $user = $this->bindingUser();

        if ($user && $workspaceId === null && ! $context->isBypassingTenantScope() && ! $this->shouldBindWithoutTenantScope()) {
            return null;
        }

        if ($this->shouldBindWithoutTenantScope()) {
            return static::withoutGlobalScope('workspace')
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->first();
        }

        return parent::resolveChildRouteBinding($childType, $value, $field);
    }

    /**
     * Explicit tenant query. Does not use the ambient context.
     */
    public static function queryForWorkspace(int $workspaceId): Builder
    {
        return static::withoutGlobalScope('workspace')
            ->where((new static)->qualifyColumn('workspace_id'), $workspaceId);
    }

    private function ensureWorkspaceContextForBinding(): void
    {
        if ($this->shouldBindWithoutTenantScope()) {
            return;
        }

        $context = app(WorkspaceContext::class);
        $user = $this->bindingUser();

        if ($user) {
            $workspace = app(WorkspaceResolverService::class)->resolveFromRequest(request(), $user);
            if ($workspace) {
                $context->set($workspace);
                app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

                return;
            }

            if (! $context->isBypassingTenantScope()) {
                $context->clear();
            }

            return;
        }

        if ($context->workspaceId() !== null || $context->isBypassingTenantScope()) {
            return;
        }
    }

    private function bindingUser(): ?User
    {
        $request = request();

        foreach ([$request->user(), Auth::guard('web')->user(), Auth::guard('sanctum')->user()] as $user) {
            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }

    private function shouldBindWithoutTenantScope(): bool
    {
        $context = app(WorkspaceContext::class);
        if ($context->isBypassingTenantScope()) {
            return true;
        }

        $request = request();
        if ($request->is('platform/*') && Auth::guard('platform_admin')->check()) {
            return true;
        }

        return false;
    }

    protected static function booted(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder): void {
            $context = app(WorkspaceContext::class);

            if ($context->isBypassingTenantScope()) {
                return;
            }

            $workspaceId = $context->workspaceId();
            if ($workspaceId === null) {
                throw MissingWorkspaceContextException::forQuery($builder->getModel()::class);
            }

            $builder->where($builder->getModel()->qualifyColumn('workspace_id'), $workspaceId);
        });

        static::creating(function (Model $model): void {
            $context = app(WorkspaceContext::class);
            $workspaceId = $context->workspaceId();
            $incomingWorkspaceId = $model->getAttribute('workspace_id');

            if ($context->isBypassingTenantScope()) {
                if (! $incomingWorkspaceId && ! $model instanceof AuditLog && ! $model instanceof EmailLog) {
                    throw new RuntimeException('workspace_id is required for privileged tenant writes.');
                }

                return;
            }

            if ($workspaceId !== null) {
                if ($incomingWorkspaceId !== null && (int) $incomingWorkspaceId !== (int) $workspaceId) {
                    throw new RuntimeException('Cross-workspace write attempt detected.');
                }

                $model->setAttribute('workspace_id', $workspaceId);

                return;
            }

            if (
                $context->allowTestFixtureMutations()
                && app()->runningUnitTests()
                && $incomingWorkspaceId
            ) {
                return;
            }

            if (($model instanceof AuditLog || $model instanceof EmailLog) && ! $incomingWorkspaceId) {
                return;
            }

            throw MissingWorkspaceContextException::forWrite($model::class, 'create');
        });

        static::updating(function (Model $model): void {
            static::assertTenantWrite($model, 'update');
        });

        static::deleting(function (Model $model): void {
            static::assertTenantWrite($model, 'delete');
        });
    }

    private static function assertTenantWrite(Model $model, string $operation): void
    {
        $context = app(WorkspaceContext::class);

        if ($context->isBypassingTenantScope()) {
            return;
        }

        $workspaceId = $context->workspaceId();
        $originalWorkspaceId = $model->getOriginal('workspace_id') ?? $model->getAttribute('workspace_id');
        $incomingWorkspaceId = $model->getAttribute('workspace_id');

        if ($workspaceId !== null) {
            if ($originalWorkspaceId !== null && (int) $originalWorkspaceId !== (int) $workspaceId) {
                throw new RuntimeException('Cross-workspace write attempt detected.');
            }

            if ($incomingWorkspaceId !== null && (int) $incomingWorkspaceId !== (int) $workspaceId) {
                throw new RuntimeException('Workspace_id cannot be changed outside current workspace context.');
            }

            return;
        }

        if ($model instanceof EmailLog && $originalWorkspaceId === null && $incomingWorkspaceId === null) {
            return;
        }

        if ($context->allowTestFixtureMutations() && app()->runningUnitTests()) {
            return;
        }

        throw MissingWorkspaceContextException::forWrite($model::class, $operation);
    }
}
