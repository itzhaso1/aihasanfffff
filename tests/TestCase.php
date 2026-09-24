<?php

namespace Tests;

use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Pos\PosOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Allows PHPUnit to seed tenant rows with an explicit workspace_id.
     * Does not unscope queries — missing context still fail-closes reads.
     */
    protected bool $allowTenantFixtureMutations = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $context = app(WorkspaceContext::class);
        $context->clear();
        if ($this->allowTenantFixtureMutations) {
            $context->enableTestFixtureMutations();
        } else {
            $context->disableTestFixtureMutations();
        }
    }

    protected function tearDown(): void
    {
        if (app()->bound(WorkspaceContext::class)) {
            $context = app(WorkspaceContext::class);
            $context->disableTestFixtureMutations();
            $context->clear();
        }

        parent::tearDown();
    }

    /**
     * Operational POS sales go through the domain service, not Web POS.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function placePosOrder(Workspace $workspace, User $actor, array $payload): Order
    {
        $order = app(PosOrderService::class)->createPosOrder($workspace, $payload, $actor);
        $isTable = ($payload['order_type'] ?? '') === 'table' || ! empty($payload['dining_table_id']);
        if (! $isTable) {
            try {
                app(PosOrderService::class)->issueWebPosDirectInvoice($order, (int) $actor->id);
            } catch (\RuntimeException) {
            }
        }

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($order): Order {
            return $order->fresh(['items', 'table', 'tableSession']) ?? $order;
        });
    }

    /**
     * Bind an explicit workspace for a test assertion or direct service call.
     * HTTP requests set their own context and clear it afterwards.
     * This does not unscope queries, pick a default workspace, or bypass fail-closed.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function inWorkspace(Workspace $workspace, Closure $callback): mixed
    {
        return app(WorkspaceContext::class)->runFor($workspace, $callback);
    }

    protected function enableWorkspaceFeature(Workspace $workspace, string $feature, bool $enabled = true): void
    {
        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => $feature],
            ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => $enabled, 'source' => 'manual']
        );
    }

    /**
     * @param  list<string>  $features
     */
    protected function enableWorkspaceFeatures(Workspace $workspace, array $features, bool $enabled = true): void
    {
        foreach ($features as $feature) {
            $this->enableWorkspaceFeature($workspace, $feature, $enabled);
        }
    }
}
