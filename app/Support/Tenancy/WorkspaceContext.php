<?php

namespace App\Support\Tenancy;

use App\Exceptions\MissingWorkspaceContextException;
use App\Models\Workspace;
use Closure;

class WorkspaceContext
{
    public const BYPASS_PLATFORM_ADMIN = 'platform-admin-panel';

    public const BYPASS_SUBSCRIPTION_SCHEDULER = 'subscription-lifecycle-scheduler';

    public const BYPASS_FINANCE_INVOICE_SCHEDULER = 'finance-invoice-status-scheduler';

    public const BYPASS_STORIES_EXPIRE_SCHEDULER = 'stories-expire-scheduler';

    /** @var list<string> */
    private const ALLOWED_BYPASS_REASONS = [
        self::BYPASS_PLATFORM_ADMIN,
        self::BYPASS_SUBSCRIPTION_SCHEDULER,
        self::BYPASS_FINANCE_INVOICE_SCHEDULER,
        self::BYPASS_STORIES_EXPIRE_SCHEDULER,
    ];

    private ?Workspace $workspace = null;

    private int $bypassDepth = 0;

    private ?string $bypassReason = null;

    /**
     * PHPUnit fixture writes only. Never enables unscoped queries.
     */
    private bool $allowTestFixtureMutations = false;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function clear(): void
    {
        $this->workspace = null;
    }

    public function workspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function workspaceId(): ?int
    {
        return $this->workspace?->id;
    }

    public function workspaceType(): ?string
    {
        return $this->workspace?->type;
    }

    public function isResolved(): bool
    {
        return $this->workspace !== null;
    }

    public function isBypassingTenantScope(): bool
    {
        return $this->bypassDepth > 0;
    }

    public function bypassReason(): ?string
    {
        return $this->bypassReason;
    }

    public function allowTestFixtureMutations(): bool
    {
        return $this->allowTestFixtureMutations;
    }

    public function enableTestFixtureMutations(): void
    {
        $this->allowTestFixtureMutations = true;
    }

    public function disableTestFixtureMutations(): void
    {
        $this->allowTestFixtureMutations = false;
    }

    /**
     * Bind a workspace for the duration of the callback, then restore the previous context.
     *
     * @template T
     *
     * @param  Closure(Workspace): T  $callback
     * @return T
     */
    public function runFor(Workspace $workspace, Closure $callback): mixed
    {
        $previous = $this->workspace;

        $this->set($workspace);

        try {
            return $callback($workspace);
        } finally {
            if ($previous instanceof Workspace) {
                $this->set($previous);
            } else {
                $this->clear();
            }
        }
    }

    /**
     * Resolve a workspace by id and bind it for the callback.
     *
     * @template T
     *
     * @param  Closure(Workspace): T  $callback
     * @return T
     */
    public function runForId(int $workspaceId, Closure $callback): mixed
    {
        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            throw MissingWorkspaceContextException::forWrite(Workspace::class, 'bind');
        }

        return $this->runFor($workspace, $callback);
    }

    /**
     * Explicit privileged path (platform admin panel, documented system jobs).
     * This is not the same as “context is missing”.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runBypassingTenantScope(string $reason, Closure $callback): mixed
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A bypass reason is required for cross-tenant access.');
        }

        if (! in_array($reason, self::ALLOWED_BYPASS_REASONS, true)) {
            throw new \InvalidArgumentException('Unknown tenant-scope bypass reason.');
        }

        $this->assertBypassRuntimeAllowed($reason);

        $this->bypassDepth++;
        $previousReason = $this->bypassReason;
        $this->bypassReason = $reason;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
            $this->bypassReason = $this->bypassDepth > 0 ? $previousReason : null;
        }
    }

    private function assertBypassRuntimeAllowed(string $reason): void
    {
        if (auth('platform_admin')->check()) {
            return;
        }

        if ($this->hasActiveHttpRoute()) {
            throw new \RuntimeException("Tenant scope bypass [{$reason}] is not allowed for tenant HTTP requests.");
        }

        if (app()->runningInConsole() || app()->runningUnitTests()) {
            return;
        }

        throw new \RuntimeException("Tenant scope bypass [{$reason}] is not allowed for tenant HTTP requests.");
    }

    private function hasActiveHttpRoute(): bool
    {
        $request = request();

        return $request->route() !== null;
    }
}
