<?php

namespace Tests;

use App\Http\Middleware\RestrictIndependentWorkerWeb;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Platform\PlatformAiConfiguration;
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

    /**
     * Domain tests still call the existing worker web controllers.
     * Production requests keep the chat-only boundary.
     */
    protected bool $bypassIndependentWorkerWebBoundary = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        if ($this->bypassIndependentWorkerWebBoundary) {
            $this->withoutMiddleware(RestrictIndependentWorkerWeb::class);
        }

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

    protected function setPlatformAi(
        string $provider,
        string $model,
        ?string $fallbackProvider = null,
        ?string $fallbackModel = null,
    ): void {
        app(PlatformAiConfiguration::class)->update(
            $provider,
            $model,
            $fallbackProvider,
            $fallbackModel,
            null,
        );
    }
}
