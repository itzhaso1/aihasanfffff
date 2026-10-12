<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Employees;

use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeResponseLength;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\EmployeeStatus;
use App\AI\Employees\Legacy\LegacyAiSettingsBridge;
use App\AI\Employees\Legacy\LegacyAiSettingsImporter;
use App\AI\Employees\Legacy\LegacyImportEntry;
use App\AI\Employees\Models\AiEmployee;
use App\Models\AiSetting;
use App\Models\Workspace;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class LegacyAiSettingsImportTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_legacy_settings_map_to_one_employee(): void
    {
        $workspace = $this->workspace();
        $setting = $this->setting($workspace, [
            'name' => 'Sara',
            'ai_role' => 'customer_support',
            'instructions' => 'Be concise.',
            'tone' => 'Short and friendly.',
            'max_tokens' => 512,
            'rules' => ['memory_enabled' => false],
            'business_information' => ['experience' => 'Five years in retail.'],
            'agent_persona' => [
                'identity' => ['name' => 'Ignored', 'role' => 'Support lead'],
                'tone' => ['language' => 'ar', 'dialect' => 'jordanian', 'formality' => 'formal', 'warmth' => 'high', 'emoji_usage' => 'none'],
                'traits' => ['calm', 'not_a_trait'],
                'behavioral_rules' => ['Never promise refunds.'],
            ],
        ]);
        $before = $this->stored($setting);
        app(InboxAgentToolRegistry::class)->register(app(SearchProductsTool::class));

        $entries = app(LegacyAiSettingsImporter::class)->import();

        $this->assertSame(LegacyImportEntry::CREATED, $entries[0]->action);
        $employee = app(EmployeeRepository::class)->find((int) $workspace->id, (int) $entries[0]->employeeId);
        $this->assertNotNull($employee);
        $this->assertSame('Sara', $employee->name);
        $this->assertSame('customer_support', $employee->role);
        $this->assertSame('Support lead', $employee->job_title);
        $this->assertSame("Be concise.\nNever promise refunds.", $employee->instructions);
        $this->assertSame('Five years in retail.', $employee->experience_notes);
        $this->assertSame('default', $employee->training_profile);
        $this->assertSame((int) $setting->id, (int) $employee->legacy_ai_setting_id);
        $this->assertTrue($employee->isDefault());
        $this->assertSame('jordanian', $employee->persona?->dialect);
        $this->assertSame('formal', $employee->persona?->formality);
        $this->assertSame('warm', $employee->persona?->tone);
        $this->assertSame('none', $employee->persona?->emoji_policy);
        $this->assertSame(['calm'], $employee->persona?->traits);
        $this->assertSame('Short and friendly.', $employee->persona?->style_notes);
        $this->assertFalse((bool) $employee->memorySettings?->conversation_memory_enabled);
        $this->assertSame(EmployeeResponseLength::Medium, $employee->modelSettings?->response_length);
        $this->assertNull($employee->modelSettings?->max_output_tokens, 'a budget equal to the length default is not an override');
        $this->assertContains('search_products', array_map(static fn ($tool): string => $tool->tool_name, $employee->toolPermissions->all()));
        $this->assertNotSame([], $employee->skillKeys());
        $this->assertSame($before, $this->stored($setting), 'ai_settings is never modified');
    }

    public function test_import_is_idempotent_even_after_soft_delete(): void
    {
        $workspace = $this->workspace();
        $this->setting($workspace, ['name' => 'Sara']);
        $importer = app(LegacyAiSettingsImporter::class);

        $importer->import();
        $second = $importer->import();
        $this->assertSame(LegacyImportEntry::SKIPPED_EXISTING, $second[0]->action);

        $this->employee($workspace, ['name' => 'Other']);
        $imported = AiEmployee::queryForWorkspace((int) $workspace->id)->whereNotNull('legacy_ai_setting_id')->firstOrFail();
        app(EmployeeService::class)->setDefault(AiEmployee::queryForWorkspace((int) $workspace->id)->where('name', 'Other')->firstOrFail());
        app(EmployeeService::class)->softDelete(app(EmployeeRepository::class)->reload($imported));

        $this->assertSame(LegacyImportEntry::SKIPPED_EXISTING, $importer->import()[0]->action);
        $this->assertSame(2, AiEmployee::queryForWorkspace((int) $workspace->id)->withTrashed()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $workspace = $this->workspace();
        $this->setting($workspace, ['name' => 'Sara']);

        $entries = app(LegacyAiSettingsImporter::class)->import(dryRun: true);

        $this->assertSame(LegacyImportEntry::WOULD_CREATE, $entries[0]->action);
        $this->assertSame('Sara', $entries[0]->name);
        $this->assertTrue($entries[0]->becomesDefault);
        $this->assertSame(0, AiEmployee::queryForWorkspace((int) $workspace->id)->withTrashed()->count());
    }

    public function test_generic_or_missing_name_uses_the_neutral_translated_label(): void
    {
        $workspace = $this->workspace();
        $this->setting($workspace, ['name' => 'AI Assistant', 'agent_persona' => null]);

        $entry = app(LegacyAiSettingsImporter::class)->import()[0];

        $this->assertSame((string) __('ai_employees.default_name'), $entry->name);
        $this->assertNotSame('مشعل', $entry->name);
    }

    public function test_inactive_legacy_assistant_imports_as_inactive_without_default(): void
    {
        $workspace = $this->workspace();
        $this->setting($workspace, ['name' => 'Sara', 'is_active' => false]);

        $entry = app(LegacyAiSettingsImporter::class)->import()[0];

        $this->assertFalse($entry->becomesDefault);
        $employee = app(EmployeeRepository::class)->find((int) $workspace->id, (int) $entry->employeeId);
        $this->assertSame(EmployeeStatus::Inactive, $employee?->status);
        $this->assertNull(app(EmployeeRepository::class)->findDefault((int) $workspace->id));
    }

    public function test_existing_default_is_kept_and_workspace_filter_limits_the_import(): void
    {
        $workspaceA = $this->workspace('A');
        $workspaceB = $this->workspace('B');
        $existing = $this->employee($workspaceA);
        $this->setting($workspaceA, ['name' => 'Sara']);
        $this->setting($workspaceB, ['name' => 'Huda']);

        $entries = app(LegacyAiSettingsImporter::class)->import(workspaceId: (int) $workspaceA->id);

        $this->assertCount(1, $entries);
        $this->assertFalse($entries[0]->becomesDefault);
        $this->assertTrue(app(EmployeeRepository::class)->reload($existing)->isDefault());
        $this->assertSame(0, AiEmployee::queryForWorkspace((int) $workspaceB->id)->count());
    }

    public function test_command_supports_dry_run(): void
    {
        $workspace = $this->workspace();
        $this->setting($workspace, ['name' => 'Sara']);

        $this->artisan('ai:employees:import-legacy', ['--dry-run' => true])
            ->expectsOutputToContain('would_create')
            ->assertSuccessful();
        $this->assertSame(0, AiEmployee::queryForWorkspace((int) $workspace->id)->count());

        $this->artisan('ai:employees:import-legacy')->expectsOutputToContain('created')->assertSuccessful();
        $this->assertSame(1, AiEmployee::queryForWorkspace((int) $workspace->id)->count());
    }

    public function test_legacy_view_returns_the_old_settings_shape(): void
    {
        $employee = $this->employee($this->workspace(), ['name' => 'Omar', 'job_title' => 'Sales']);

        $view = app(LegacyAiSettingsBridge::class)->legacyView($employee);

        $this->assertSame('Omar', $view['assistant_name']);
        $this->assertSame('sales_agent', $view['ai_role']);
        $this->assertSame('Sales', $view['agent_persona']['identity']['role']);
        $this->assertSame('saudi', $view['agent_persona']['tone']['dialect']);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(AiSetting $setting): array
    {
        return (array) AiSetting::withoutGlobalScopes()->toBase()->where('id', $setting->id)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function setting(Workspace $workspace, array $attributes): AiSetting
    {
        return AiSetting::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
            'temperature' => 0.3,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
