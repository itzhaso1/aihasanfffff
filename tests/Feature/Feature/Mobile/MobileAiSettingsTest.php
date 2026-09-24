<?php

namespace Tests\Feature\Feature\Mobile;

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

class MobileAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_get_and_update_mobile_ai_settings(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'Mobile Original');

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Mobile Original');

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'name' => 'Mobile Updated',
                'instructions' => 'تعليمات جديدة',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mobile Updated')
            ->assertJsonPath('data.instructions', 'تعليمات جديدة')
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(
            'Mobile Updated',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );
    }

    public function test_member_without_permission_cannot_access_mobile_ai_settings(): void
    {
        [, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $member = $this->memberWithPermissions($workspace, []);
        $this->seedAiSetting($workspace, 'Protected');

        $this->actingAsMobile($member, $workspace)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertForbidden();

        $this->actingAsMobile($member, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_workspace_isolation_on_mobile_ai_settings(): void
    {
        [$ownerA, $workspaceA] = $this->seedWorkspace('WS A');
        [$ownerB, $workspaceB] = $this->seedWorkspace('WS B');
        $this->enableWorkspaceFeature($workspaceA, 'ai');
        $this->enableWorkspaceFeature($workspaceB, 'ai');
        $this->seedAiSetting($workspaceA, 'Settings A');
        $this->seedAiSetting($workspaceB, 'Settings B');

        $this->actingAsMobile($ownerA, $workspaceA)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertJsonPath('data.name', 'Settings A');

        $this->actingAsMobile($ownerA, $workspaceB)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertForbidden();

        $this->assertSame(
            'Settings B',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspaceB->id)
                ->value('name')
        );
    }

    public function test_owner_can_update_structured_agent_persona(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'Persona Host');

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertOk()
            ->assertJsonPath('meta.persona_catalog.version', 1)
            ->assertJsonPath('meta.ai_roles.0', 'sales_agent');

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'agent_persona' => [
                    'version' => 1,
                    'identity' => [
                        'name' => 'ليلى',
                        'role' => 'Sales assistant',
                    ],
                    'traits' => ['helpful', 'professional'],
                    'tone' => [
                        'language' => 'ar',
                        'dialect' => 'jordanian',
                        'formality' => 'professional',
                        'warmth' => 'medium',
                        'verbosity' => 'concise',
                        'emoji_usage' => 'minimal',
                    ],
                    'style' => [
                        'greeting_style' => 'friendly',
                        'sales_style' => 'helpful_not_pushy',
                        'support_style' => 'empathetic_and_direct',
                    ],
                    'behavioral_rules' => ['Never invent prices.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.agent_persona.identity.name', 'ليلى')
            ->assertJsonPath('data.agent_persona.tone.dialect', 'jordanian');

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertJsonPath('data.agent_persona.identity.name', 'ليلى');
    }

    public function test_invalid_agent_persona_is_rejected(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'Persona Host');

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'agent_persona' => [
                    'version' => 1,
                    'identity' => ['name' => 'X'],
                    'traits' => [],
                    'tone' => [],
                    'style' => [],
                    'behavioral_rules' => [],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_ai_feature_disabled_blocks_mobile_ai_settings(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai', false);
        $this->seedAiSetting($workspace, 'Disabled');

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertStatus(402);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedWorkspace(string $name = 'Mobile AI WS'): array
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

        $plan = Plan::query()
            ->where('workspace_type', 'company')
            ->where('is_active', true)
            ->first();

        if ($plan) {
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

        return [$user, $workspace];
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

        if ($permissions !== []) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($workspace->id);
            $user->givePermissionTo($permissions);
            $registrar->setPermissionsTeamId(null);
        }

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

    private function actingAsMobile(User $user, Workspace $workspace)
    {
        $this->flushHeaders();

        $token = $user->createToken('mobile-ai-settings');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
