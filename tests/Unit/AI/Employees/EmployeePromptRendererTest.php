<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Employees;

use App\AI\Core\Prompt\PromptLayer;
use App\AI\Employees\EmployeePromptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class EmployeePromptRendererTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_identity_and_persona_come_from_the_employee_record(): void
    {
        $employee = $this->employee($this->workspace(), [
            'name' => 'Layla',
            'job_title' => 'Sales advisor',
            'role' => 'sales_agent',
            'instructions' => 'Always mention delivery times.',
            'training_profile' => 'default',
            'experience_notes' => 'Knows the Riyadh delivery window.',
            'persona' => ['language' => 'ar', 'dialect' => 'saudi', 'tone' => 'warm'],
            'skills' => ['sales', 'product_search'],
        ]);

        $byKey = [];
        foreach ((new EmployeePromptRenderer)->sections($employee) as $section) {
            $this->assertSame(PromptLayer::Employee, $section->layer);
            $byKey[$section->key] = $section->content;
        }

        $this->assertStringContainsString('Speak as this configured employee', $byKey['employee.identity']);
        $this->assertStringContainsString('only identity you may state', $byKey['employee.identity']);
        $this->assertStringContainsString('not nationality', $byKey['employee.identity']);
        $this->assertStringContainsString('Do not guess', $byKey['employee.identity']);
        $this->assertStringContainsString('do not add a technical identity disclosure', $byKey['employee.identity']);
        $this->assertStringNotContainsString('Then answer that in one short line', $byKey['employee.identity']);
        $this->assertStringContainsString('Name: Layla', $byKey['employee.identity']);
        $this->assertStringContainsString('Job title: Sales advisor', $byKey['employee.identity']);
        $this->assertStringContainsString('Role: sales_agent', $byKey['employee.identity']);
        $this->assertStringContainsString('Domain: default', $byKey['employee.identity']);
        $this->assertStringContainsString('Skills:', $byKey['employee.identity']);
        $this->assertStringContainsString('sales', $byKey['employee.identity']);
        $this->assertStringContainsString('product_search', $byKey['employee.identity']);
        $this->assertStringNotContainsString('AI Assistant', $byKey['employee.identity']);
        $this->assertStringContainsString('Language: ar', $byKey['employee.communication']);
        $this->assertStringContainsString('Dialect: saudi', $byKey['employee.communication']);
        $this->assertStringContainsString('Tone: warm', $byKey['employee.communication']);
        $this->assertSame('Always mention delivery times.', $byKey['employee.instructions']);
        $this->assertSame('Knows the Riyadh delivery window.', $byKey['employee.experience']);
    }

    public function test_identity_uses_each_employee_name_without_hardcoding_another(): void
    {
        $workspace = $this->workspace();
        $first = $this->employee($workspace, ['name' => 'Omar', 'role' => 'sales_agent']);
        $second = $this->employee($workspace, ['name' => 'Huda', 'role' => 'customer_support']);
        $renderer = new EmployeePromptRenderer;

        $firstIdentity = $renderer->sections($first)[0]->content;
        $secondIdentity = $renderer->sections($second)[0]->content;

        $this->assertStringContainsString('Name: Omar', $firstIdentity);
        $this->assertStringNotContainsString('Huda', $firstIdentity);
        $this->assertStringContainsString('Name: Huda', $secondIdentity);
        $this->assertStringNotContainsString('Omar', $secondIdentity);
        $this->assertStringNotContainsString('مشعل', $firstIdentity);
        $this->assertStringNotContainsString('طارق', $secondIdentity);
    }
}
