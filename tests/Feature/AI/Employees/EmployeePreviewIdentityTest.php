<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Employees;

use App\AI\Core\AiResponseStatus;
use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Employees\Preview\EmployeePreviewRunner;
use App\AI\Models\ModelSettingsResolver;
use App\Models\AiSetting;
use App\Services\Platform\PlatformAiConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AI\Core\ScriptedModelGateway;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class EmployeePreviewIdentityTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_preview_resolves_the_selected_employee_identity_into_the_model_request(): void
    {
        Http::fake();
        $workspace = $this->workspace();
        $employee = $this->employee($workspace, [
            'name' => 'مشعل',
            'job_title' => 'مساعد مبيعات وخدمة عملاء',
            'role' => 'sales_agent',
            'instructions' => 'جاوب باللهجة السعودية.',
            'training_profile' => 'default',
            'persona' => ['language' => 'ar', 'dialect' => 'saudi', 'tone' => 'warm'],
            'skills' => ['sales', 'product_search'],
            'model' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
        ]);
        AiSetting::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'AI Assistant',
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
            'is_active' => true,
        ]);
        $gateway = new ScriptedModelGateway([new ModelResponse('هلا والله')]);
        $this->app->instance(ModelGateway::class, $gateway);
        $counts = $this->rowCounts();

        $response = app(EmployeePreviewRunner::class)->run($employee, 'انت من وين يا مشعل؟');

        $this->assertSame(AiResponseStatus::Replied, $response->status);
        $this->assertSame('هلا والله', $response->text);
        $this->assertFalse($response->isDeliverable());
        $this->assertSame((int) $employee->id, $response->decision->employeeId);
        $this->assertSame((int) $employee->id, $gateway->requests[0]->employeeId);
        $this->assertSame([], $gateway->requests[0]->tools);
        $settings = app(ModelSettingsResolver::class)->resolve((int) $employee->id);
        $platform = app(PlatformAiConfiguration::class)->snapshot();
        $this->assertSame($platform->provider, $settings->provider);
        $this->assertSame($platform->model, $settings->model);

        $identity = $this->section($gateway->requests[0], PromptLayer::Employee, 'employee.identity');
        $communication = $this->section($gateway->requests[0], PromptLayer::Employee, 'employee.communication');
        $instructions = $this->section($gateway->requests[0], PromptLayer::Employee, 'employee.instructions');
        $employeeContext = $this->section($gateway->requests[0], PromptLayer::Context, 'context.employee');

        $this->assertStringContainsString('Speak as this configured employee', $identity);
        $this->assertStringContainsString('not nationality', $identity);
        $this->assertStringContainsString('Do not guess', $identity);
        $this->assertStringContainsString('unless the customer explicitly asks', $identity);
        $this->assertStringContainsString('Name: مشعل', $identity);
        $this->assertStringContainsString('Role: sales_agent', $identity);
        $this->assertStringContainsString('Domain: default', $identity);
        $this->assertStringContainsString('Dialect: saudi', $communication);
        $this->assertStringContainsString('Language: ar', $communication);
        $this->assertSame('جاوب باللهجة السعودية.', $instructions);
        $this->assertStringContainsString('"employee_id":'.$employee->id, $employeeContext);
        $this->assertStringContainsString('"role":"sales_agent"', $employeeContext);
        $this->assertStringContainsString('"language":"ar"', $employeeContext);
        $this->assertStringContainsString('"dialect":"saudi"', $employeeContext);
        $this->assertStringContainsString('"domain":"default"', $employeeContext);
        $this->assertStringNotContainsString('مشعل', $employeeContext);
        $this->assertStringNotContainsString('AI Assistant', $identity);
        $this->assertStringNotContainsString('AI Assistant', $employeeContext);
        $this->assertStringNotContainsString('Name: AI Assistant', $this->layerText($gateway->requests[0], PromptLayer::Employee));
        $this->assertSame($counts, $this->rowCounts());
        Http::assertNothingSent();
    }

    public function test_switching_employees_changes_preview_identity_and_keeps_each_model(): void
    {
        Http::fake();
        $workspace = $this->workspace();
        $first = $this->employee($workspace, [
            'name' => 'مشعل',
            'role' => 'sales_agent',
            'instructions' => 'بيع بالسعودي.',
            'persona' => ['language' => 'ar', 'dialect' => 'saudi'],
            'model' => ['provider' => 'openai', 'model' => 'gpt-4o-mini'],
        ]);
        $second = $this->employee($workspace, [
            'name' => 'طارق',
            'role' => 'customer_support',
            'instructions' => 'ادعم بالأردني.',
            'persona' => ['language' => 'ar', 'dialect' => 'jordanian'],
            'model' => ['provider' => 'google_ai_studio', 'model' => 'gemini-2.0-flash'],
        ]);
        $gateway = new ScriptedModelGateway([new ModelResponse('أول'), new ModelResponse('ثاني')]);
        $this->app->instance(ModelGateway::class, $gateway);
        $runner = app(EmployeePreviewRunner::class);

        $firstResponse = $runner->run($first, 'انت من وين يا مشعل؟');
        $secondResponse = $runner->run($second, 'انت مين يا طارق؟');

        $this->assertSame('أول', $firstResponse->text);
        $this->assertSame('ثاني', $secondResponse->text);
        $this->assertSame((int) $first->id, $gateway->requests[0]->employeeId);
        $this->assertSame((int) $second->id, $gateway->requests[1]->employeeId);

        $firstIdentity = $this->section($gateway->requests[0], PromptLayer::Employee, 'employee.identity');
        $secondIdentity = $this->section($gateway->requests[1], PromptLayer::Employee, 'employee.identity');
        $firstContext = $this->section($gateway->requests[0], PromptLayer::Context, 'context.employee');
        $secondContext = $this->section($gateway->requests[1], PromptLayer::Context, 'context.employee');

        $this->assertStringContainsString('Name: مشعل', $firstIdentity);
        $this->assertStringNotContainsString('طارق', $firstIdentity);
        $this->assertStringContainsString('Name: طارق', $secondIdentity);
        $this->assertStringNotContainsString('مشعل', $secondIdentity);
        $this->assertStringContainsString('Dialect: saudi', $this->section($gateway->requests[0], PromptLayer::Employee, 'employee.communication'));
        $this->assertStringContainsString('Dialect: jordanian', $this->section($gateway->requests[1], PromptLayer::Employee, 'employee.communication'));
        $this->assertStringContainsString('بيع بالسعودي.', $this->section($gateway->requests[0], PromptLayer::Employee, 'employee.instructions'));
        $this->assertStringContainsString('ادعم بالأردني.', $this->section($gateway->requests[1], PromptLayer::Employee, 'employee.instructions'));
        $this->assertStringContainsString('"employee_id":'.$first->id, $firstContext);
        $this->assertStringContainsString('"role":"sales_agent"', $firstContext);
        $this->assertStringContainsString('"employee_id":'.$second->id, $secondContext);
        $this->assertStringContainsString('"role":"customer_support"', $secondContext);
        $platform = app(PlatformAiConfiguration::class)->snapshot();
        $this->inWorkspace($workspace, function () use ($first, $second, $platform): void {
            $this->assertSame($platform->model, $first->fresh()->modelSettings?->model);
            $this->assertSame($platform->model, $second->fresh()->modelSettings?->model);
        });
        $this->assertSame((int) $first->id, $firstResponse->decision->employeeId);
        $this->assertSame((int) $second->id, $secondResponse->decision->employeeId);
        Http::assertNothingSent();
    }

    public function test_social_questions_keep_employee_facts_and_do_not_invent_missing_ones(): void
    {
        Http::fake();
        $workspace = $this->workspace();
        $employee = $this->employee($workspace, [
            'name' => 'Nora',
            'job_title' => 'Sales advisor',
            'role' => 'sales_agent',
            'persona' => ['language' => 'ar', 'dialect' => 'saudi', 'tone' => 'warm'],
        ]);
        AiSetting::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'AI Assistant',
            'provider' => 'google_ai_studio',
            'model' => 'gemini-2.5-flash',
            'is_active' => true,
        ]);
        $questions = [
            'من أنت؟',
            'وش دورك؟',
            'وش اسمك؟',
            'وش جنسيتك؟',
            'من وين؟',
            'هل أنت سعودي؟',
            'كم عمرك؟',
            'هل أنت إنسان؟',
            'أنت ذكاء اصطناعي؟',
        ];
        $gateway = new ScriptedModelGateway(array_fill(0, count($questions), new ModelResponse('تمام')));
        $this->app->instance(ModelGateway::class, $gateway);
        $runner = app(EmployeePreviewRunner::class);

        foreach ($questions as $question) {
            $response = $runner->run($employee, $question);
            $request = $gateway->requests[array_key_last($gateway->requests)];
            $identity = $this->section($request, PromptLayer::Employee, 'employee.identity');
            $conversation = $this->section($request, PromptLayer::Training, 'training.rule.conversation');
            $saudi = $this->section($request, PromptLayer::Training, 'training.rule.saudi_arabic');

            $this->assertSame(AiResponseStatus::Replied, $response->status);
            $this->assertFalse($response->isDeliverable());
            $this->assertSame($question, $request->prompt->userMessage()?->content);
            $this->assertStringContainsString('Name: Nora', $identity);
            $this->assertStringContainsString('Role: sales_agent', $identity);
            $this->assertStringContainsString('Job title: Sales advisor', $identity);
            $this->assertStringNotContainsString('Nationality:', $identity);
            $this->assertStringNotContainsString('Age:', $identity);
            $this->assertStringNotContainsString('AI Assistant', $identity);
            $this->assertStringContainsString('not nationality', $identity);
            $this->assertStringContainsString('Do not guess', $identity);
            $this->assertStringContainsString('unless the customer explicitly asks', $identity);
            $this->assertStringContainsString('answer it first', $conversation);
            $this->assertStringContainsString('not nationality', $conversation);
            $this->assertStringContainsString('Dialect is speech, not nationality', $saudi);
            $this->assertStringNotContainsString('ابشر أبشر', $saudi);
            $this->assertStringContainsString('Dialect: saudi', $this->section($request, PromptLayer::Employee, 'employee.communication'));
        }

        $this->assertCount(count($questions), $gateway->requests);
        Http::assertNothingSent();
    }

    private function section(ModelRequest $request, PromptLayer $layer, string $key): string
    {
        foreach ($request->prompt->forLayer($layer) as $section) {
            if ($section->key === $key) {
                return $section->content;
            }
        }

        $this->fail($key.' was missing from the '.$layer->key().' layer.');
    }

    private function layerText(ModelRequest $request, PromptLayer $layer): string
    {
        return implode("\n", array_map(static fn ($section): string => $section->content, $request->prompt->forLayer($layer)));
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['messages', 'conversations', 'ai_logs', 'ai_employees', 'ai_settings', 'chat_catalogs'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
