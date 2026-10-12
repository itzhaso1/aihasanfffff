<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Employees;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ContextPayload;
use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\EmployeeTrainingQueryFactory;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Training\LibraryTrainingProvider;
use App\AI\Training\TrainingContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

/** Training selection follows the employee's language and dialect, never its name. */
class EmployeeSaudiTrainingTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    private const SAUDI_RULE = 'rule.saudi_arabic';

    public function test_arabic_saudi_employee_gets_the_saudi_arabic_rule(): void
    {
        $employee = $this->employee($this->workspace(), ['name' => 'Anything', 'persona' => ['language' => 'ar', 'dialect' => 'saudi']]);

        $this->assertContains(self::SAUDI_RULE, $this->selectedTraining($employee));
    }

    public function test_english_employee_with_saudi_dialect_does_not_get_it(): void
    {
        $employee = $this->employee($this->workspace(), ['persona' => ['language' => 'en', 'dialect' => 'saudi']]);

        $this->assertNotContains(self::SAUDI_RULE, $this->selectedTraining($employee));
    }

    public function test_other_dialects_do_not_get_it(): void
    {
        $employee = $this->employee($this->workspace(), ['persona' => ['language' => 'ar', 'dialect' => 'jordanian']]);

        $this->assertNotContains(self::SAUDI_RULE, $this->selectedTraining($employee));
    }

    public function test_the_employee_name_does_not_affect_training(): void
    {
        $workspace = $this->workspace();
        $a = $this->employee($workspace, ['name' => 'مشعل']);
        $b = $this->employee($workspace, ['name' => 'Zed']);

        $this->assertSame($this->selectedTraining($a), $this->selectedTraining($b));
    }

    /**
     * @return list<string>
     */
    private function selectedTraining(AiEmployee $employee): array
    {
        $request = new AiRequest((int) $employee->workspace_id, null, (int) $employee->id, new AiInboundMessage('هلا'), AiRunMode::Preview);
        $resolved = app(DatabaseEmployeeResolver::class)->resolve($request);
        $provider = new LibraryTrainingProvider(new TrainingContextBuilder, app(EmployeeTrainingQueryFactory::class));

        return array_map(static fn ($version): string => $version->id, $provider->select($request, $resolved, new ContextPayload)->versions);
    }
}
