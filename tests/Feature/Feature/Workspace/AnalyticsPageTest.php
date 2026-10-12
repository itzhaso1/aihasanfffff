<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_page_smoke_with_feature_enabled(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $this->enableFeature($workspace, 'analytics');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.analytics.index'))
            ->assertOk()
            ->assertSee('التحليلات')
            ->assertSee('الإيرادات');
    }

    public function test_analytics_page_respects_date_range_filter(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');
        $this->enableFeature($workspace, 'analytics');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.analytics.index', ['range' => 'today']))
            ->assertOk()
            ->assertSee('اليوم');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function enableFeature(Workspace $workspace, string $feature): void
    {
        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => $feature],
            ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
        );
    }
}
