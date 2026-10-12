<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Employees;

use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeOperationException;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeResponseLength;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\EmployeeStatus;
use App\AI\Employees\InvalidEmployeeData;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Employees\Models\AiEmployeeModelSettings;
use App\AI\Employees\Models\AiEmployeePersona;
use App\AI\Models\ModelSettingsResolver;
use App\Models\AiSetting;
use App\Services\Platform\PlatformAiConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class EmployeeServiceTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    private EmployeeService $service;

    private EmployeeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmployeeService::class);
        $this->repository = app(EmployeeRepository::class);
    }

    public function test_migrations_create_the_employee_tables_and_nullable_binding_columns(): void
    {
        foreach (['ai_employees', 'ai_employee_personas', 'ai_employee_skills', 'ai_employee_tool_permissions', 'ai_employee_memory_settings', 'ai_employee_model_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumn($table, 'workspace_id'), $table.'.workspace_id');
        }
        $this->assertTrue(Schema::hasColumn('conversations', 'ai_employee_id'));
        $this->assertTrue(Schema::hasColumn('ai_logs', 'ai_employee_id'));
        $this->assertTrue(Schema::hasColumn('ai_employees', 'deleted_at'));
    }

    public function test_first_active_employee_becomes_default_and_later_ones_do_not(): void
    {
        $workspace = $this->workspace();
        $first = $this->employee($workspace);
        $second = $this->employee($workspace, ['name' => 'Omar']);

        $this->assertTrue($first->isDefault());
        $this->assertFalse($second->isDefault());
        $this->assertSame(1, $first->config_version);
        $this->assertSame($first->id, $this->repository->findDefault((int) $workspace->id)?->id);
    }

    public function test_set_default_moves_the_single_default_slot(): void
    {
        $workspace = $this->workspace();
        $first = $this->employee($workspace);
        $second = $this->employee($workspace, ['name' => 'Omar']);

        $this->service->setDefault($second);

        $this->assertFalse($this->repository->reload($first)->isDefault());
        $this->assertTrue($this->repository->reload($second)->isDefault());
        $this->assertSame(1, AiEmployee::queryForWorkspace((int) $workspace->id)->whereNotNull('default_slot')->count());
    }

    public function test_database_rejects_a_second_default_in_the_same_workspace(): void
    {
        $workspace = $this->workspace();
        $this->employee($workspace);
        $second = $this->employee($workspace, ['name' => 'Omar']);

        $this->expectException(QueryException::class);
        AiEmployee::queryForWorkspace((int) $workspace->id)->whereKey($second->id)->update(['default_slot' => AiEmployee::DEFAULT_SLOT]);
    }

    public function test_each_workspace_has_its_own_default(): void
    {
        $a = $this->employee($this->workspace('A'));
        $b = $this->employee($this->workspace('B'));

        $this->assertTrue($a->isDefault());
        $this->assertTrue($b->isDefault());
    }

    public function test_inactive_employee_cannot_become_default_and_deactivation_clears_default(): void
    {
        $workspace = $this->workspace();
        $first = $this->employee($workspace);
        $second = $this->service->deactivate($this->employee($workspace, ['name' => 'Omar']));

        try {
            $this->service->setDefault($second);
            $this->fail('Inactive employee became default.');
        } catch (EmployeeOperationException) {
        }

        $first = $this->service->deactivate($first);
        $this->assertSame(EmployeeStatus::Inactive, $first->status);
        $this->assertFalse($first->isDefault());
        $this->assertNull($this->repository->findDefault((int) $workspace->id));

        $second = $this->service->activate($second);
        $this->assertTrue($second->isDefault(), 'activation fills an empty default slot');
    }

    public function test_default_cannot_be_deleted_and_delete_is_soft(): void
    {
        $workspace = $this->workspace();
        $first = $this->employee($workspace);
        $second = $this->employee($workspace, ['name' => 'Omar']);

        try {
            $this->service->softDelete($first);
            $this->fail('Default employee was deleted.');
        } catch (EmployeeOperationException) {
        }

        $this->service->softDelete($second);
        $this->assertNull($this->repository->find((int) $workspace->id, (int) $second->id));
        $this->assertNotNull(AiEmployee::queryForWorkspace((int) $workspace->id)->withTrashed()->find($second->id)?->deleted_at);
    }

    public function test_rename_keeps_the_id_and_bumps_the_config_version(): void
    {
        $workspace = $this->workspace();
        $employee = $this->employee($workspace);

        $renamed = $this->service->rename($employee, 'Layla');

        $this->assertSame($employee->id, $renamed->id);
        $this->assertSame('Layla', $renamed->name);
        $this->assertSame(2, $renamed->config_version);
        $this->assertTrue($renamed->isDefault());
        $this->assertSame(['sales'], $renamed->skillKeys(), 'unrelated configuration survives a rename');
    }

    public function test_update_merges_persona_and_replaces_skills_and_tools(): void
    {
        $employee = $this->employee($this->workspace(), ['tools' => ['search_products']]);

        $updated = $this->service->update($employee, [
            'persona' => ['tone' => 'calm'],
            'skills' => ['customer_support', 'sales'],
            'tools' => [['name' => 'search_products', 'allowed' => false]],
        ]);

        $this->assertSame('calm', $updated->persona?->tone);
        $this->assertSame('saudi', $updated->persona?->dialect, 'unspecified persona keys are kept');
        $this->assertSame(['customer_support', 'sales'], $updated->skillKeys());
        $this->assertCount(1, $updated->toolPermissions);
        $this->assertFalse((bool) $updated->toolPermissions[0]->allowed);
    }

    public function test_persona_and_model_settings_are_stored_on_employee_tables_not_ai_settings(): void
    {
        $workspace = $this->workspace();
        $employee = $this->employee($workspace, ['model' => ['provider' => 'google_ai_studio', 'model' => 'gemini-2.5-flash', 'response_length' => 'medium']]);

        $this->assertSame(1, AiEmployeePersona::queryForWorkspace((int) $workspace->id)->where('ai_employee_id', $employee->id)->count());
        $model = AiEmployeeModelSettings::queryForWorkspace((int) $workspace->id)->where('ai_employee_id', $employee->id)->firstOrFail();
        $this->assertSame(EmployeeResponseLength::Medium, $model->response_length);
        $this->assertSame(512, $model->effectiveMaxOutputTokens());
        $this->assertSame(0, AiSetting::withoutGlobalScopes()->count());
    }

    public function test_invalid_values_are_rejected_from_their_single_sources(): void
    {
        $factory = app(EmployeeFactory::class);
        $cases = [
            'role' => ['name' => 'X', 'role' => 'wizard'],
            'skills' => ['name' => 'X', 'role' => 'sales_agent', 'skills' => ['flying']],
            'persona.dialect' => ['name' => 'X', 'role' => 'sales_agent', 'persona' => ['dialect' => 'klingon']],
            'name' => ['name' => '<b>x</b>', 'role' => 'sales_agent'],
        ];
        foreach ($cases as $field => $payload) {
            try {
                $factory->fromArray($payload);
                $this->fail('Accepted invalid '.$field);
            } catch (InvalidEmployeeData $exception) {
                $this->assertSame($field, $exception->field);
            }
        }
    }

    public function test_unknown_model_in_payload_is_replaced_by_the_platform_model(): void
    {
        $workspace = $this->workspace();
        $employee = $this->employee($workspace, ['model' => ['temperature' => 0.4, 'response_length' => 'short']]);
        AiEmployeeModelSettings::query()
            ->withoutGlobalScope('workspace')
            ->where('ai_employee_id', $employee->id)
            ->update(['model' => 'not-a-model']);

        $platform = app(PlatformAiConfiguration::class)->snapshot();
        $resolved = app(ModelSettingsResolver::class)->resolve((int) $employee->id);
        $this->assertSame($platform->model, $resolved->model);
        $this->assertSame($platform->provider, $resolved->provider);
    }

    public function test_unknown_training_profile_is_rejected(): void
    {
        $this->expectException(EmployeeOperationException::class);
        $this->employee($this->workspace(), ['training_profile' => 'missing_profile']);
    }

    public function test_repository_never_returns_another_workspace_employee(): void
    {
        $workspaceA = $this->workspace('A');
        $workspaceB = $this->workspace('B');
        $employeeB = $this->employee($workspaceB);

        $this->assertNull($this->repository->find((int) $workspaceA->id, (int) $employeeB->id));
        $this->assertNull($this->repository->findForWorkspace($workspaceA, (int) $employeeB->id));
        $this->assertFalse($this->repository->exists((int) $workspaceA->id, (int) $employeeB->id));
        $this->assertCount(0, $this->repository->listForWorkspace((int) $workspaceA->id));
        $this->assertCount(1, $this->repository->listForWorkspace((int) $workspaceB->id));
    }

    public function test_config_rows_belong_to_the_employee_workspace(): void
    {
        $workspace = $this->workspace();
        $employee = $this->employee($workspace, ['tools' => ['search_products']]);

        foreach ([$employee->persona, $employee->memorySettings, $employee->modelSettings, $employee->skills[0], $employee->toolPermissions[0]] as $row) {
            $this->assertSame((int) $workspace->id, (int) $row->workspace_id);
        }
    }
}
