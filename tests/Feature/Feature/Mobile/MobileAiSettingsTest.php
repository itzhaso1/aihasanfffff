<?php

namespace Tests\Feature\Feature\Mobile;

use App\Models\AiSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Platform\PlatformAiConfiguration;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            ->assertJsonPath('data.name', 'Mobile Original')
            ->assertJsonPath('data.assistant_name', 'Mobile Original');

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'name' => 'Mobile Updated',
                'instructions' => 'تعليمات جديدة',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mobile Updated')
            ->assertJsonPath('data.assistant_name', 'Mobile Updated')
            ->assertJsonPath('data.instructions', 'تعليمات جديدة')
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(
            'Mobile Updated',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );
    }

    public function test_mobile_settings_cannot_change_the_platform_model(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'Provider Host');
        $this->setPlatformAi('google_ai_studio', 'gemini-2.5-flash');

        $show = $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertOk()
            ->assertJsonPath('meta.providers.0.id', 'google_ai_studio')
            ->assertJsonPath('meta.providers.0.label', 'Google AI Studio (Gemini)')
            ->assertJsonPath('meta.providers.1.id', 'openai')
            ->assertJsonPath('meta.providers.1.label', 'OpenAI')
            ->assertJsonPath('meta.platform_ai.provider', 'google_ai_studio')
            ->assertJsonPath('meta.platform_ai.model', 'gemini-2.5-flash')
            ->assertJsonPath('data.provider', 'google_ai_studio')
            ->assertJsonPath('data.model', 'gemini-2.5-flash');

        $this->assertContains('gemini-2.5-flash', $show->json('meta.providers.0.models'));
        $this->assertContains('gpt-4o-mini', $show->json('meta.providers.1.models'));

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
            ])
            ->assertStatus(422);

        $this->setPlatformAi('openai', 'gpt-4o-mini');

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.model', 'gpt-4o-mini');
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
            ->assertJsonPath('data.name', 'Settings A')
            ->assertJsonPath('data.assistant_name', 'Settings A');

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

    public function test_owner_can_save_and_read_assistant_name_alias(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');

        $this->actingAsMobile($owner, $workspace)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'AI Assistant')
            ->assertJsonPath('data.assistant_name', 'AI Assistant');

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'assistant_name' => 'ساره',
            ])
            ->assertOk()
            ->assertJsonPath('data.assistant_name', 'ساره')
            ->assertJsonPath('data.name', 'ساره');

        $this->assertSame(
            'ساره',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'assistant_name' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.assistant_name', 'AI Assistant')
            ->assertJsonPath('data.name', 'AI Assistant');
    }

    public function test_assistant_name_validation_rejects_markup_and_invalid_characters(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'ساره');

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'assistant_name' => '<script>محمد</script>',
            ])
            ->assertStatus(422);

        $this->actingAsMobile($owner, $workspace)
            ->putJson('/api/mobile/v1/ai/settings', [
                'assistant_name' => 'محمد@zatca',
            ])
            ->assertStatus(422);

        $this->assertSame(
            'ساره',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->value('name')
        );
    }

    public function test_assistant_name_is_isolated_between_workspaces(): void
    {
        [$ownerA, $workspaceA] = $this->seedWorkspace('WS A');
        [$ownerB, $workspaceB] = $this->seedWorkspace('WS B');
        $this->enableWorkspaceFeature($workspaceA, 'ai');
        $this->enableWorkspaceFeature($workspaceB, 'ai');
        $this->seedAiSetting($workspaceA, 'ساره');
        $this->seedAiSetting($workspaceB, 'حسن');

        $this->actingAsMobile($ownerA, $workspaceA)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertJsonPath('data.assistant_name', 'ساره');

        $this->actingAsMobile($ownerB, $workspaceB)
            ->getJson('/api/mobile/v1/ai/settings')
            ->assertJsonPath('data.assistant_name', 'حسن');

        $this->actingAsMobile($ownerA, $workspaceA)
            ->putJson('/api/mobile/v1/ai/settings', ['assistant_name' => 'هشام'])
            ->assertOk()
            ->assertJsonPath('data.assistant_name', 'هشام');

        $this->assertSame(
            'حسن',
            AiSetting::withoutGlobalScopes()
                ->where('workspace_id', $workspaceB->id)
                ->value('name')
        );
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

    public function test_probe_reports_disconnected_without_leaking_provider_secrets(): void
    {
        config(['services.google_ai_studio.key' => null]);
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'مشعل');
        $platform = app(PlatformAiConfiguration::class)->snapshot();

        $this->actingAsMobile($owner, $workspace)
            ->postJson('/api/mobile/v1/ai/probe', ['message' => 'مرحبا، عرفني بنفسك'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.model', $platform->model)
            ->assertJsonMissingPath('data.api_key');
    }

    public function test_probe_uses_persona_voice_instead_of_raw_instructions(): void
    {
        config(['services.google_ai_studio.key' => 'test-key']);
        $this->setPlatformAi('google_ai_studio', 'gemini-2.5-flash');
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => "ارحب انا مشعل كيف اساعدك ؟\nوش المنتج اللي تدو"]]],
                    'finishReason' => 'MAX_TOKENS',
                ]],
                'usageMetadata' => ['totalTokenCount' => 20],
            ], 200),
        ]);
        [$owner, $workspace] = $this->seedWorkspace();
        $this->enableWorkspaceFeature($workspace, 'ai');
        $this->seedAiSetting($workspace, 'مشعل')->update([
            'instructions' => 'أنت "مشعل"، مساعد ذكاء اصطناعي سعودي.',
        ]);

        $this->actingAsMobile($owner, $workspace)
            ->postJson('/api/mobile/v1/ai/probe', ['message' => 'مرحبا، عرفني بنفسك'])
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.reply', 'ارحب انا مشعل كيف اساعدك ؟');

        Http::assertSent(function ($request): bool {
            $system = (string) data_get($request->data(), 'system_instruction.parts.0.text');

            return str_contains($system, '## Agent Persona')
                && str_contains($system, 'ارحب انا مشعل كيف اساعدك ؟')
                && str_contains($system, 'even if workspace instructions describe you that way')
                && data_get($request->data(), 'generationConfig.thinkingConfig.thinkingBudget') === 0;
        });
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
