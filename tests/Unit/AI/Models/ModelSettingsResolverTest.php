<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Models;

use App\AI\Employees\EmployeeModelPolicy;
use App\AI\Employees\Models\AiEmployeeModelSettings;
use App\AI\Models\ModelGatewayException;
use App\AI\Models\ModelSettingsResolver;
use App\AI\Models\ProviderId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class ModelSettingsResolverTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_resolver_uses_platform_model_and_employee_temperature(): void
    {
        $this->setPlatformAi('google_ai_studio', 'gemini-2.5-flash');
        $workspace = $this->workspace();
        $employee = $this->employee($workspace, [
            'model' => [
                'temperature' => 0.9,
                'response_length' => 'short',
                'thinking_budget' => 128,
            ],
        ]);

        $resolved = app(ModelSettingsResolver::class)->resolve((int) $employee->id);

        $this->assertSame((int) $employee->id, $resolved->employeeId);
        $this->assertSame((int) $workspace->id, $resolved->workspaceId);
        $this->assertSame(ProviderId::GEMINI, $resolved->provider);
        $this->assertSame('gemini-2.5-flash', $resolved->model);
        $this->assertSame(0.9, $resolved->temperature);
        $this->assertSame(256, $resolved->maxOutputTokens);
        $this->assertSame(128, $resolved->thinkingBudget);
    }

    public function test_missing_employee_is_rejected(): void
    {
        $this->expectException(ModelGatewayException::class);
        $this->expectExceptionMessage('Employee model settings were not found.');
        app(ModelSettingsResolver::class)->resolve(999999);
    }

    public function test_stale_employee_model_row_is_ignored_in_favor_of_platform(): void
    {
        $this->setPlatformAi('google_ai_studio', 'gemini-2.5-flash');
        $workspace = $this->workspace();
        $employee = $this->employee($workspace);
        AiEmployeeModelSettings::query()
            ->withoutGlobalScope('workspace')
            ->where('ai_employee_id', $employee->id)
            ->update(['provider' => 'openai', 'model' => 'not-an-allowed-model']);

        $resolved = app(ModelSettingsResolver::class)->resolve((int) $employee->id);

        $this->assertSame(ProviderId::GEMINI, $resolved->provider);
        $this->assertSame('gemini-2.5-flash', $resolved->model);
    }

    public function test_platform_openai_selection_is_used_even_when_employee_row_differs(): void
    {
        $this->setPlatformAi('openai', 'gpt-4o-mini');
        $workspace = $this->workspace();
        $employee = $this->employee($workspace);

        $resolved = app(ModelSettingsResolver::class)->resolve((int) $employee->id);

        $this->assertSame(ProviderId::OPENAI, $resolved->provider);
        $this->assertSame('gpt-4o-mini', $resolved->model);
    }

    public function test_employee_without_override_resolves_policy_defaults(): void
    {
        $workspace = $this->workspace();
        $employee = $this->employee($workspace);
        AiEmployeeModelSettings::query()
            ->withoutGlobalScope('workspace')
            ->where('ai_employee_id', $employee->id)
            ->delete();

        $defaults = app(EmployeeModelPolicy::class)->defaults();
        $resolved = app(ModelSettingsResolver::class)->resolve((int) $employee->id);

        $this->assertSame((int) $employee->id, $resolved->employeeId);
        $this->assertSame($defaults->provider, $resolved->provider);
        $this->assertSame($defaults->model, $resolved->model);
    }
}
