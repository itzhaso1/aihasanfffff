<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Employees;

use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Data\ModelResponse;
use App\AI\Employees\EmployeeModelPolicy;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Employees\Models\AiEmployeeModelSettings;
use App\Models\AiSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Platform\PlatformAiConfiguration;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AI\Core\ScriptedModelGateway;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class AiEmployeeApiTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    private const BASE = '/api/mobile/v1/ai';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_create_list_show_and_update_employees(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $platform = app(PlatformAiConfiguration::class)->snapshot();

        $created = $this->as($owner, $workspace)->postJson(self::BASE.'/employees', [
            'name' => 'Nora',
            'role' => 'sales_agent',
            'job_title' => 'Sales advisor',
            'persona' => ['language' => 'ar', 'dialect' => 'saudi', 'tone' => 'warm'],
            'skills' => ['sales', 'product_search'],
            'tools' => [['name' => 'search_products']],
            'model' => ['response_length' => 'short'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Nora')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.persona.dialect', 'saudi')
            ->assertJsonPath('data.skills.1.key', 'sales')
            ->assertJsonPath('data.model.response_length', 'short')
            ->assertJsonPath('data.model.provider', $platform->provider)
            ->assertJsonPath('data.model.model', $platform->model)
            ->assertJsonPath('data.model.source', 'platform')
            ->assertJsonPath('data.model.is_override', false);
        $id = (int) $created->json('data.id');

        $this->as($owner, $workspace)->getJson(self::BASE.'/employees')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonStructure([
                'meta' => [
                    'persona_options' => ['languages', 'dialects', 'tones', 'traits'],
                    'providers' => ['*' => ['id', 'label', 'models']],
                ],
            ]);

        $this->as($owner, $workspace)->patchJson(self::BASE.'/employees/'.$id, ['name' => 'Layla', 'persona' => ['tone' => 'calm']])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Layla')
            ->assertJsonPath('data.persona.tone', 'calm')
            ->assertJsonPath('data.persona.dialect', 'saudi')
            ->assertJsonPath('data.config_version', 2);

        $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$id)->assertOk()->assertJsonPath('data.name', 'Layla');
    }

    public function test_create_from_preset_with_name_override(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees', ['preset_key' => 'support_jordanian', 'name' => 'Sami'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sami')
            ->assertJsonPath('data.preset_key', 'support_jordanian')
            ->assertJsonPath('data.persona.dialect', 'jordanian');

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees', ['preset_key' => 'nope'])->assertStatus(422);
    }

    public function test_default_activation_and_deletion_rules(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $first = $this->employee($workspace);
        $second = $this->employee($workspace, ['name' => 'Omar']);

        $this->as($owner, $workspace)->deleteJson(self::BASE.'/employees/'.$first->id)->assertStatus(422);
        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$second->id.'/default')->assertOk()->assertJsonPath('data.is_default', true);
        $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$first->id)->assertJsonPath('data.is_default', false);

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$first->id.'/deactivate')->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$first->id.'/default')->assertStatus(422);
        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$first->id.'/activate')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.is_default', false);

        $this->as($owner, $workspace)->deleteJson(self::BASE.'/employees/'.$first->id)->assertOk();
        $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$first->id)->assertNotFound();
        $this->as($owner, $workspace)->getJson(self::BASE.'/employees')->assertJsonCount(1, 'data');
    }

    public function test_validation_errors_are_422_with_the_field(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees', ['name' => 'X', 'role' => 'wizard'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->as($owner, $workspace)->postJson(self::BASE.'/employees', ['name' => 'X', 'role' => 'sales_agent', 'skills' => ['flying']])
            ->assertStatus(422)->assertJsonValidationErrors('skills');
        $this->as($owner, $workspace)->postJson(self::BASE.'/employees', ['name' => 'X', 'role' => 'sales_agent', 'persona' => ['dialect' => 'klingon']])
            ->assertStatus(422)->assertJsonValidationErrors('persona.dialect');
        $this->as($owner, $workspace)->postJson(self::BASE.'/employees', ['name' => 'X', 'role' => 'sales_agent', 'model' => ['provider' => 'google_ai_studio', 'model' => 'gpt-9000']])
            ->assertStatus(422);
        $this->as($owner, $workspace)->postJson(self::BASE.'/employees', ['name' => str_repeat('a', 65), 'role' => 'sales_agent'])
            ->assertStatus(422);
        $this->assertSame(0, AiEmployee::queryForWorkspace((int) $workspace->id)->count());
        $employee = $this->employee($workspace);
        $this->as($owner, $workspace)->patchJson(self::BASE.'/employees/'.$employee->id, ['skills' => ['flying']])
            ->assertStatus(422)->assertJsonValidationErrors('skills');
        $this->assertSame(1, AiEmployee::queryForWorkspace((int) $workspace->id)->count());
    }

    public function test_other_workspace_employees_are_not_found_on_every_route(): void
    {
        [$ownerA, $workspaceA] = $this->mobileWorkspace('A');
        [, $workspaceB] = $this->mobileWorkspace('B');
        $foreign = $this->employee($workspaceB);
        $this->employee($workspaceB, ['name' => 'Second']);
        $client = $this->as($ownerA, $workspaceA);

        $client->getJson(self::BASE.'/employees/'.$foreign->id)->assertNotFound();
        $client->patchJson(self::BASE.'/employees/'.$foreign->id, ['name' => 'Hacked'])->assertNotFound();
        $client->deleteJson(self::BASE.'/employees/'.$foreign->id)->assertNotFound();
        foreach (['default', 'activate', 'deactivate'] as $action) {
            $client->postJson(self::BASE.'/employees/'.$foreign->id.'/'.$action)->assertNotFound();
        }
        $client->postJson(self::BASE.'/employees/'.$foreign->id.'/test', ['message' => 'hi'])->assertNotFound();
        $client->getJson(self::BASE.'/employees')->assertJsonCount(0, 'data');

        $stored = AiEmployee::queryForWorkspace((int) $workspaceB->id)->findOrFail($foreign->id);
        $this->assertSame('Nora', $stored->name);
        $this->assertSame('active', $stored->status->value);
    }

    public function test_members_without_ai_permission_are_forbidden(): void
    {
        [, $workspace] = $this->mobileWorkspace();
        $employee = $this->employee($workspace);
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['membership_role' => 'member', 'status' => 'active', 'joined_at' => now()]);
        $client = $this->as($member, $workspace);

        $client->getJson(self::BASE.'/employees')->assertForbidden();
        $client->postJson(self::BASE.'/employees', ['name' => 'X', 'role' => 'sales_agent'])->assertForbidden();
        $client->patchJson(self::BASE.'/employees/'.$employee->id, ['name' => 'X'])->assertForbidden();
        $client->postJson(self::BASE.'/employees/'.$employee->id.'/default')->assertForbidden();
        $client->postJson(self::BASE.'/employees/'.$employee->id.'/test', ['message' => 'hi'])->assertForbidden();
        $client->getJson(self::BASE.'/employee-presets')->assertForbidden();
        $client->getJson(self::BASE.'/skills')->assertForbidden();
    }

    public function test_ai_feature_gate_applies(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace(enableAi: false);

        $this->as($owner, $workspace)->getJson(self::BASE.'/employees')->assertStatus(402);
    }

    public function test_presets_and_skills_catalogs(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();

        $presets = $this->as($owner, $workspace)->getJson(self::BASE.'/employee-presets')->assertOk();
        $this->assertSame(['general_neutral', 'sales_saudi', 'support_jordanian'], array_column($presets->json('data'), 'key'));
        $this->assertSame(0, AiEmployee::queryForWorkspace((int) $workspace->id)->count(), 'listing presets creates nothing');

        $skills = $this->as($owner, $workspace)->getJson(self::BASE.'/skills')->assertOk();
        $this->assertSame(
            ['complaint_handling', 'customer_support', 'faq', 'human_handoff', 'order_handling', 'product_recommendation', 'product_search', 'sales'],
            array_column($skills->json('data'), 'key'),
        );
        $this->assertSame('catalog', $skills->json('data.5.category'));
        $this->assertSame(['search_products'], $skills->json('data.6.allowed_tools'));
        $this->assertSame(1, $skills->json('data.7.version'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $skills->json('data.0.fingerprint'));
    }

    public function test_preview_test_never_sends_or_stores_anything(): void
    {
        Http::fake();
        [$owner, $workspace] = $this->mobileWorkspace();
        $employee = $this->employee($workspace);
        $gateway = new ScriptedModelGateway([new ModelResponse('هلا والله')]);
        $this->app->instance(ModelGateway::class, $gateway);
        $counts = $this->rowCounts();

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$employee->id.'/test', ['message' => 'هلا'])
            ->assertOk()
            ->assertJsonPath('data.status', 'replied')
            ->assertJsonPath('data.outcome', 'success')
            ->assertJsonPath('data.text', 'هلا والله')
            ->assertJsonPath('data.deliverable', false)
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.provider', $employee->modelSettings?->provider)
            ->assertJsonPath('data.model', $employee->modelSettings?->model)
            ->assertJsonPath('data.used_model', $employee->modelSettings?->model)
            ->assertJsonPath('data.settings_source', 'platform')
            ->assertJsonPath('data.decision.run_mode', 'preview')
            ->assertJsonPath('data.decision.employee_id', $employee->id)
            ->assertJsonPath('data.checks.2.ok', true)
            ->assertJsonPath('data.checks.2.warning', false);

        $this->assertSame([], $gateway->requests[0]->tools, 'no tools are offered in preview');
        $this->assertSame($counts, $this->rowCounts());
        Http::assertNothingSent();
    }

    public function test_preview_uses_the_platform_admin_model(): void
    {
        Http::fake();
        [$owner, $workspace] = $this->mobileWorkspace();
        $this->setPlatformAi('openai', 'gpt-4o-mini');
        $employee = $this->employee($workspace, [
            'model' => ['temperature' => 0.2, 'response_length' => 'short'],
        ]);
        $this->as($owner, $workspace)->patchJson(self::BASE.'/employees/'.$employee->id, [
            'model' => ['provider' => 'google_ai_studio', 'model' => 'gemini-2.0-flash'],
        ])->assertStatus(422);

        $gateway = new ScriptedModelGateway([new ModelResponse('openai-preview')]);
        $this->app->instance(ModelGateway::class, $gateway);

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$employee->id.'/test', ['message' => 'هلا'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'success')
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.model', 'gpt-4o-mini')
            ->assertJsonPath('data.used_model', 'gpt-4o-mini')
            ->assertJsonPath('data.settings_source', 'platform')
            ->assertJsonPath('data.employee_id', $employee->id);

        $this->assertSame((int) $employee->id, $gateway->requests[0]->employeeId);
        $this->assertSame([], $gateway->requests[0]->tools);
        Http::assertNothingSent();
    }

    public function test_preview_without_a_usable_provider_fails_with_a_structured_reason(): void
    {
        Http::preventStrayRequests();
        config(['services.google_ai_studio.key' => null, 'services.openai.key' => null]);
        [$owner, $workspace] = $this->mobileWorkspace();
        $employee = $this->employee($workspace);

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$employee->id.'/test', ['message' => 'هلا'])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.outcome', 'failed')
            ->assertJsonPath('data.text', null)
            ->assertJsonPath('data.deliverable', false)
            ->assertJsonPath('data.decision.failure.stage', 'call_model')
            ->assertJsonPath('data.decision.failure.code', 'model_error')
            ->assertJsonMissingPath('data.api_key');

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$employee->id.'/test', [])->assertStatus(422);
    }

    public function test_preview_timeout_or_provider_exception_remains_a_failure(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $employee = $this->employee($workspace);
        $this->app->instance(ModelGateway::class, new ScriptedModelGateway([new \RuntimeException('timed out')]));

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$employee->id.'/test', ['message' => 'هلا'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'failed')
            ->assertJsonPath('data.checks.0.ok', false)
            ->assertJsonPath('data.decision.failure.code', 'model_error');
    }

    public function test_employee_api_does_not_touch_legacy_ai_settings(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $setting = AiSetting::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'name' => 'Legacy', 'provider' => 'google_ai_studio', 'model' => 'gemini-2.5-flash', 'is_active' => true]);
        $before = (array) DB::table('ai_settings')->where('id', $setting->id)->first();

        $id = $this->as($owner, $workspace)->postJson(self::BASE.'/employees', ['name' => 'Nora', 'role' => 'sales_agent'])->json('data.id');
        $this->as($owner, $workspace)->patchJson(self::BASE.'/employees/'.$id, ['model' => ['temperature' => 0.5]])->assertOk();

        $this->assertSame($before, (array) DB::table('ai_settings')->where('id', $setting->id)->first());
        $this->as($owner, $workspace)->getJson(self::BASE.'/settings')->assertOk()->assertJsonPath('data.name', 'Legacy');
    }

    public function test_service_rename_keeps_api_identity(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $employee = $this->employee($workspace);
        app(EmployeeService::class)->rename($employee, 'Renamed');

        $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$employee->id)
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.is_default', true);
    }

    public function test_employee_api_always_returns_the_platform_model(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $this->setPlatformAi('openai', 'gpt-4o-mini');
        $first = $this->employee($workspace, [
            'name' => 'First',
            'model' => ['temperature' => 0.2, 'response_length' => 'short'],
        ]);
        $second = $this->employee($workspace, [
            'name' => 'Second',
            'model' => ['temperature' => 0.8, 'response_length' => 'medium'],
        ]);

        $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$first->id)
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.model.provider', 'openai')
            ->assertJsonPath('data.model.model', 'gpt-4o-mini')
            ->assertJsonPath('data.model.source', 'platform')
            ->assertJsonPath('data.model.is_override', false)
            ->assertJsonPath('data.model.temperature', 0.2);

        $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$second->id)
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.model.provider', 'openai')
            ->assertJsonPath('data.model.model', 'gpt-4o-mini')
            ->assertJsonPath('data.model.source', 'platform')
            ->assertJsonPath('data.model.temperature', 0.8);
    }

    public function test_employee_without_override_returns_resolved_defaults(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $employee = $this->employee($workspace);
        AiEmployeeModelSettings::query()
            ->withoutGlobalScope('workspace')
            ->where('ai_employee_id', $employee->id)
            ->delete();
        $defaults = app(EmployeeModelPolicy::class)->defaults();

        $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.model.provider', $defaults->provider)
            ->assertJsonPath('data.model.model', $defaults->model)
            ->assertJsonPath('data.model.source', 'platform')
            ->assertJsonPath('data.model.is_override', false);
    }

    public function test_preview_uses_the_platform_model_for_every_employee(): void
    {
        Http::fake();
        [$owner, $workspace] = $this->mobileWorkspace();
        $this->setPlatformAi('openai', 'gpt-4o-mini');
        AiSetting::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Legacy',
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
            'is_active' => true,
        ]);
        $first = $this->employee($workspace, ['name' => 'First']);
        $second = $this->employee($workspace, ['name' => 'Second']);
        $gateway = new ScriptedModelGateway([
            new ModelResponse('first-preview'),
            new ModelResponse('second-preview'),
        ]);
        $this->app->instance(ModelGateway::class, $gateway);

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$first->id.'/test', ['message' => 'هلا'])
            ->assertOk()
            ->assertJsonPath('data.employee_id', $first->id)
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.model', 'gpt-4o-mini')
            ->assertJsonPath('data.used_model', 'gpt-4o-mini')
            ->assertJsonPath('data.settings_source', 'platform');

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$second->id.'/test', ['message' => 'هلا'])
            ->assertOk()
            ->assertJsonPath('data.employee_id', $second->id)
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.model', 'gpt-4o-mini')
            ->assertJsonPath('data.used_model', 'gpt-4o-mini')
            ->assertJsonPath('data.settings_source', 'platform');

        $this->assertSame((int) $first->id, $gateway->requests[0]->employeeId);
        $this->assertSame((int) $second->id, $gateway->requests[1]->employeeId);
        Http::assertNothingSent();
    }

    public function test_preview_without_override_reports_default_used_model(): void
    {
        Http::fake();
        [$owner, $workspace] = $this->mobileWorkspace();
        $employee = $this->employee($workspace);
        AiEmployeeModelSettings::query()
            ->withoutGlobalScope('workspace')
            ->where('ai_employee_id', $employee->id)
            ->delete();
        $defaults = app(EmployeeModelPolicy::class)->defaults();
        $this->app->instance(ModelGateway::class, new ScriptedModelGateway([new ModelResponse('default-preview')]));

        $this->as($owner, $workspace)->postJson(self::BASE.'/employees/'.$employee->id.'/test', ['message' => 'هلا'])
            ->assertOk()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.provider', $defaults->provider)
            ->assertJsonPath('data.model', $defaults->model)
            ->assertJsonPath('data.used_model', $defaults->model)
            ->assertJsonPath('data.settings_source', 'platform');
    }

    public function test_saving_employee_temperature_does_not_change_the_platform_model(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $this->setPlatformAi('google_ai_studio', 'gemini-2.5-flash');
        $employee = $this->employee($workspace);

        $this->as($owner, $workspace)->patchJson(self::BASE.'/employees/'.$employee->id, [
            'model' => ['temperature' => 0.7, 'response_length' => 'medium'],
        ])->assertOk()
            ->assertJsonPath('data.id', $employee->id)
            ->assertJsonPath('data.model.provider', 'google_ai_studio')
            ->assertJsonPath('data.model.model', 'gemini-2.5-flash')
            ->assertJsonPath('data.model.temperature', 0.7)
            ->assertJsonPath('data.model.source', 'platform')
            ->assertJsonMissingPath('data.model.thinking_budget');

        $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$employee->id)
            ->assertOk()
            ->assertJsonPath('data.model.provider', 'google_ai_studio')
            ->assertJsonPath('data.model.model', 'gemini-2.5-flash')
            ->assertJsonPath('data.model.temperature', 0.7)
            ->assertJsonMissingPath('data.model.thinking_budget');
    }

    public function test_employee_apis_do_not_expose_provider_secrets_or_env_values(): void
    {
        config([
            'services.google_ai_studio.key' => 'secret-gemini-test-key',
            'services.openai.key' => 'sk-test-openai-key',
        ]);
        [$owner, $workspace] = $this->mobileWorkspace();
        $employee = $this->employee($workspace);

        foreach ([
            $this->as($owner, $workspace)->getJson(self::BASE.'/employees'),
            $this->as($owner, $workspace)->getJson(self::BASE.'/employees/'.$employee->id),
            $this->as($owner, $workspace)->getJson(self::BASE.'/settings'),
        ] as $response) {
            $response->assertOk();
            $this->assertNoProviderSecrets($response->getContent());
        }
    }

    public function test_owner_receives_a_safe_provider_status_summary(): void
    {
        config([
            'services.google_ai_studio.key' => 'secret-gemini-test-key',
            'services.openai.key' => '',
        ]);
        [$owner, $workspace] = $this->mobileWorkspace();

        $response = $this->as($owner, $workspace)->getJson(self::BASE.'/employees')->assertOk();
        $this->assertNoProviderSecrets($response->getContent());
        $response->assertJsonPath('meta.provider_status.0.provider', 'google_ai_studio')
            ->assertJsonPath('meta.provider_status.0.credentials_present', true)
            ->assertJsonPath('meta.provider_status.1.provider', 'openai')
            ->assertJsonPath('meta.provider_status.1.credentials_present', false);
        $this->assertIsArray($response->json('meta.provider_status.0.allowed_models'));
        $this->assertNotEmpty($response->json('meta.provider_status.0.default_model'));
    }

    public function test_manager_does_not_receive_provider_status(): void
    {
        [$owner, $workspace] = $this->mobileWorkspace();
        $manager = User::factory()->create();
        $workspace->users()->attach($manager->id, ['membership_role' => 'manager', 'status' => 'active', 'joined_at' => now()]);

        $this->as($manager, $workspace)->getJson(self::BASE.'/employees')
            ->assertOk()
            ->assertJsonMissingPath('meta.provider_status');
        $this->as($owner, $workspace)->getJson(self::BASE.'/employees')
            ->assertOk()
            ->assertJsonPath('meta.provider_status.0.provider', 'google_ai_studio');
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['messages', 'conversations', 'ai_logs', 'ai_employees', 'ai_settings'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function mobileWorkspace(string $name = 'Employee API WS', bool $enableAi = true): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company', 'name' => $name]);
        $workspace->users()->attach($user->id, ['membership_role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                ['workspace_id' => $workspace->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()],
            );
        }
        $this->enableWorkspaceFeature($workspace, 'ai', $enableAi);

        return [$user, $workspace];
    }

    private function as(User $user, Workspace $workspace): static
    {
        $this->flushHeaders();
        $token = $user->createToken('mobile-ai-employees');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }

    private function assertNoProviderSecrets(string $content): void
    {
        $this->assertStringNotContainsString('secret-gemini-test-key', $content);
        $this->assertStringNotContainsString('sk-test-openai-key', $content);
        $this->assertStringNotContainsString('GEMINI_API_KEY', $content);
        $this->assertStringNotContainsString('OPENAI_API_KEY', $content);
        $this->assertStringNotContainsString('thinking_budget', $content);
        $this->assertDoesNotMatchRegularExpression('/(?i)api[_-]?key\\s*[:=]/', $content);
    }
}
