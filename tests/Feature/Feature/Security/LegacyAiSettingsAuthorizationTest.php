<?php

namespace Tests\Feature\Feature\Security;

use App\Models\AiSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LegacyAiSettingsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_read_and_update_legacy_ai_settings(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy AI Owner');
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'Owner Original');

        $headers = $this->workspaceHeaders($workspace);
        Sanctum::actingAs($owner);

        $this->withHeaders($headers)
            ->getJson('/api/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'Owner Original');

        $this->withHeaders($headers)
            ->putJson('/api/ai/settings', [
                'name' => 'Owner Updated',
                'instructions' => 'Updated instructions',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Owner Updated');

        $this->assertSame(
            'Owner Updated',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );
    }

    public function test_manager_can_read_and_update_legacy_ai_settings(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy AI Manager');
        $this->enableWorkspaceFeature($workspace, 'ai');
        $manager = $this->attachMember($workspace, 'manager');
        $this->seedAiSetting($workspace, 'Manager Original');

        Sanctum::actingAs($manager);

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->getJson('/api/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'Manager Original');

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->putJson('/api/ai/settings', ['name' => 'Manager Updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Manager Updated');
    }

    public function test_member_with_ai_manage_permission_can_read_and_update_legacy_ai_settings(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy AI Permission');
        $this->enableWorkspaceFeature($workspace, 'ai');
        $member = $this->memberWithPermissions($workspace, ['ai.manage']);
        $this->seedAiSetting($workspace, 'Permission Original');

        Sanctum::actingAs($member);

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->getJson('/api/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'Permission Original');

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->putJson('/api/ai/settings', ['name' => 'Permission Updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Permission Updated');
    }

    public function test_member_without_permission_cannot_read_or_update_legacy_ai_settings(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy AI Member');
        $this->enableWorkspaceFeature($workspace, 'ai');
        $member = $this->attachMember($workspace, 'member');
        $this->seedAiSetting($workspace, 'Protected Name');

        Sanctum::actingAs($member);
        $headers = $this->workspaceHeaders($workspace);

        $this->withHeaders($headers)
            ->getJson('/api/ai/settings')
            ->assertForbidden();

        $this->withHeaders($headers)
            ->putJson('/api/ai/settings', ['name' => 'Hacked Name'])
            ->assertForbidden();

        $this->assertSame(
            'Protected Name',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );
    }

    public function test_non_member_cannot_access_legacy_ai_settings(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy AI Non Member');
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'Private Name');
        $outsider = User::factory()->create();

        Sanctum::actingAs($outsider);

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->getJson('/api/ai/settings')
            ->assertForbidden();

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->putJson('/api/ai/settings', ['name' => 'Outsider Name'])
            ->assertForbidden();

        $this->assertSame(
            'Private Name',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );
    }

    public function test_cross_workspace_access_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->makeWorkspace('Legacy AI A');
        [$ownerB, $workspaceB] = $this->makeWorkspace('Legacy AI B');
        $this->enableWorkspaceFeature($workspaceA, 'ai');
        $this->enableWorkspaceFeature($workspaceB, 'ai');
        $this->seedAiSetting($workspaceB, 'Workspace B Secret');

        Sanctum::actingAs($ownerA);

        $this->withHeaders($this->workspaceHeaders($workspaceB))
            ->getJson('/api/ai/settings')
            ->assertForbidden();

        $this->withHeaders($this->workspaceHeaders($workspaceB))
            ->putJson('/api/ai/settings', ['name' => 'Cross Workspace Hack'])
            ->assertForbidden();

        $this->assertSame(
            'Workspace B Secret',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspaceB->id)
                ->value('name')
        );
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy AI Guest');
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'Guest Protected');

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->getJson('/api/ai/settings')
            ->assertUnauthorized();

        $this->withHeaders($this->workspaceHeaders($workspace))
            ->putJson('/api/ai/settings', ['name' => 'Guest Hack'])
            ->assertUnauthorized();

        $this->assertSame(
            'Guest Protected',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );
    }

    public function test_ai_feature_disabled_blocks_legacy_ai_settings_endpoints(): void
    {
        [$owner, $workspace] = $this->makeWorkspace('Legacy AI Disabled');
        $this->enableWorkspaceFeature($workspace, 'ai', false);
        $this->seedAiSetting($workspace, 'Disabled Original');

        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeaders($workspace);

        $this->withHeaders($headers)
            ->getJson('/api/ai/settings')
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'ai');

        $this->withHeaders($headers)
            ->putJson('/api/ai/settings', ['name' => 'Disabled Hack'])
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'ai');

        $this->assertSame(
            'Disabled Original',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );
    }

    public function test_legacy_ai_settings_remain_isolated_between_workspaces_for_authorized_user(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->attachWorkspace($user, 'Legacy AI Shared A', 'owner');
        $workspaceB = $this->attachWorkspace($user, 'Legacy AI Shared B', 'owner');
        $this->enableWorkspaceFeature($workspaceA, 'ai');
        $this->enableWorkspaceFeature($workspaceB, 'ai');
        $this->seedAiSetting($workspaceA, 'Settings A');
        $this->seedAiSetting($workspaceB, 'Settings B');

        Sanctum::actingAs($user);

        $this->withHeaders($this->workspaceHeaders($workspaceA))
            ->getJson('/api/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'Settings A');

        $this->withHeaders($this->workspaceHeaders($workspaceB))
            ->getJson('/api/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'Settings B');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspace(string $name): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->ensureSubscription($workspace);

        return [$user, $workspace];
    }

    private function attachWorkspace(User $user, string $name, string $role): Workspace
    {
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->ensureSubscription($workspace);

        return $workspace;
    }

    private function attachMember(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function memberWithPermissions(Workspace $workspace, array $permissions): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($workspace->id);
        $user->givePermissionTo($permissions);
        $registrar->setPermissionsTeamId(null);

        return $user;
    }

    private function seedAiSetting(Workspace $workspace, string $name): AiSetting
    {
        return AiSetting::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'workspace_id' => $workspace->id,
                'name' => $name,
                'provider' => 'google_ai_studio',
                'model' => 'gemini-2.5-flash',
                'max_tokens' => 512,
                'temperature' => 0.3,
                'is_active' => true,
            ]
        );
    }

    private function ensureSubscription(Workspace $workspace): void
    {
        $plan = Plan::query()
            ->where('workspace_type', 'company')
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            return;
        }

        Subscription::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeaders(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
