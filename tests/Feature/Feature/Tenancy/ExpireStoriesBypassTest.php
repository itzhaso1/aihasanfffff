<?php

namespace Tests\Feature\Feature\Tenancy;

use App\Exceptions\MissingWorkspaceContextException;
use App\Jobs\Stories\ExpireStoriesJob;
use App\Models\PlatformAdmin;
use App\Models\Story;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Stories\StoryService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class ExpireStoriesBypassTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_expire_requires_explicit_bypass(): void
    {
        [$workspaceA] = $this->twoWorkspaces();
        $this->expiredStory($workspaceA);

        $this->expectException(MissingWorkspaceContextException::class);
        app(StoryService::class)->expireOldStories();
    }

    public function test_scheduler_job_uses_documented_bypass_reason_and_expires_due_stories(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $dueA = $this->expiredStory($workspaceA);
        $dueB = $this->expiredStory($workspaceB);
        $activeA = $this->activeStory($workspaceA);

        $job = new ExpireStoriesJob;
        $job->handle(app(StoryService::class));

        $this->assertSame(Story::STATUS_EXPIRED, $dueA->fresh()->status);
        $this->assertSame(Story::STATUS_EXPIRED, $dueB->fresh()->status);
        $this->assertSame(Story::STATUS_ACTIVE, $activeA->fresh()->status);
        $this->assertFalse(app(WorkspaceContext::class)->isBypassingTenantScope());
        $this->assertNull(app(WorkspaceContext::class)->bypassReason());
    }

    public function test_unknown_bypass_reason_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(WorkspaceContext::class)->runBypassingTenantScope('please-unscope-everything', fn () => null);
    }

    public function test_tenant_workspace_expire_does_not_touch_other_workspace(): void
    {
        [$workspaceA, $workspaceB] = $this->twoWorkspaces();
        $dueA = $this->expiredStory($workspaceA);
        $dueB = $this->expiredStory($workspaceB);

        app(WorkspaceContext::class)->runFor($workspaceA, function () use ($workspaceA): void {
            $this->assertFalse(app(WorkspaceContext::class)->isBypassingTenantScope());
            app(StoryService::class)->expireOldStories($workspaceA);
        });

        $this->assertSame(Story::STATUS_EXPIRED, $dueA->fresh()->status);
        $this->assertSame(Story::STATUS_ACTIVE, $dueB->fresh()->status);
    }

    public function test_platform_admin_request_does_not_leave_bypass_enabled_for_stories(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform_admin');
        $this->getJson('/platform/dashboard')->assertOk();

        $this->assertFalse(app(WorkspaceContext::class)->isBypassingTenantScope());
        $this->expectException(MissingWorkspaceContextException::class);
        app(StoryService::class)->expireOldStories();
    }

    public function test_tenant_http_request_cannot_start_stories_expire_bypass(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $request = Request::create('/workspace/dashboard', 'GET');
        $route = new Route('GET', '/workspace/dashboard', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not allowed for tenant HTTP requests');

        app(WorkspaceContext::class)->runBypassingTenantScope(
            WorkspaceContext::BYPASS_STORIES_EXPIRE_SCHEDULER,
            fn () => null,
        );
    }

    /**
     * @return array{0: Workspace, 1: Workspace}
     */
    private function twoWorkspaces(): array
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        return [
            Workspace::factory()->create(['owner_user_id' => $userA->id, 'type' => 'company']),
            Workspace::factory()->create(['owner_user_id' => $userB->id, 'type' => 'company']),
        ];
    }

    private function expiredStory(Workspace $workspace): Story
    {
        $user = User::factory()->create();

        return Story::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'type' => Story::TYPE_TEXT,
            'body_text' => 'old',
            'visibility' => Story::VISIBILITY_WORKSPACE,
            'expires_at' => now()->subHour(),
            'status' => Story::STATUS_ACTIVE,
        ]);
    }

    private function activeStory(Workspace $workspace): Story
    {
        $user = User::factory()->create();

        return Story::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'type' => Story::TYPE_TEXT,
            'body_text' => 'live',
            'visibility' => Story::VISIBILITY_WORKSPACE,
            'expires_at' => now()->addDay(),
            'status' => Story::STATUS_ACTIVE,
        ]);
    }
}
