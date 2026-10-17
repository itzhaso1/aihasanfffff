<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Employees;

use App\AI\Constitution\ConstitutionLoader;
use App\AI\Core\AiFailureCode;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\AiRunMode;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Employees\ConversationEmployeeBinder;
use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\Resolution\NoEmployeeAvailable;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AI\Core\AiRuntimeHarness;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class EmployeeResolverTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_conversation_employee_wins_when_active(): void
    {
        $workspace = $this->workspace();
        $this->employee($workspace);
        $assigned = $this->employee($workspace, ['name' => 'Omar']);
        $conversation = $this->conversation($workspace, (int) $assigned->id);

        $resolved = $this->resolver()->resolve($this->request($workspace, $conversation->id));

        $this->assertSame((int) $assigned->id, $resolved->employeeId);
        $this->assertSame('1', $resolved->configVersion);
        $this->assertSame(['sales'], $resolved->skillKeys);
    }

    public function test_inactive_conversation_employee_falls_back_to_default(): void
    {
        $workspace = $this->workspace();
        $default = $this->employee($workspace);
        $assigned = app(EmployeeService::class)->deactivate($this->employee($workspace, ['name' => 'Omar']));
        $conversation = $this->conversation($workspace, (int) $assigned->id);

        $this->assertSame((int) $default->id, $this->resolver()->resolve($this->request($workspace, $conversation->id))->employeeId);
    }

    public function test_unbound_conversation_uses_the_default(): void
    {
        $workspace = $this->workspace();
        $this->employee($workspace, ['name' => 'Omar']);
        $conversation = $this->conversation($workspace, null);

        $this->assertSame('Omar', $this->employeeName($this->resolver()->resolve($this->request($workspace, $conversation->id))->sections));
    }

    public function test_changing_the_default_moves_conversations_bound_to_the_previous_default(): void
    {
        $workspace = $this->workspace();
        $previous = $this->employee($workspace, ['name' => 'مشعل']);
        $current = $this->employee($workspace, ['name' => 'محمد']);
        $conversation = $this->conversation($workspace, (int) $previous->id);

        app(EmployeeService::class)->setDefault($current);

        $this->assertSame(
            (int) $current->id,
            (int) Conversation::withoutGlobalScopes()->whereKey($conversation->id)->value('ai_employee_id'),
        );
        $resolved = $this->resolver()->resolve($this->request($workspace, $conversation->id));
        $this->assertSame((int) $current->id, $resolved->employeeId);
        $this->assertSame('محمد', $this->employeeName($resolved->sections));
    }

    public function test_another_workspace_conversation_or_employee_is_ignored(): void
    {
        $workspaceA = $this->workspace('A');
        $workspaceB = $this->workspace('B');
        $defaultA = $this->employee($workspaceA);
        $employeeB = $this->employee($workspaceB);
        $conversationB = $this->conversation($workspaceB, (int) $employeeB->id);

        $this->assertSame((int) $defaultA->id, $this->resolver()->resolve($this->request($workspaceA, $conversationB->id))->employeeId);
        $this->assertSame((int) $defaultA->id, $this->resolver()->resolve($this->request($workspaceA, null, AiRunMode::Preview, (int) $employeeB->id))->employeeId);
    }

    public function test_no_active_employee_fails_resolution_and_the_runtime_reports_it(): void
    {
        $workspace = $this->workspace();
        app(EmployeeService::class)->deactivate($this->employee($workspace));

        try {
            $this->resolver()->resolve($this->request($workspace, null));
            $this->fail('Resolved an employee for a workspace without active employees.');
        } catch (NoEmployeeAvailable) {
        }

        $harness = new AiRuntimeHarness;
        $harness->employees = $this->resolver();
        $response = $harness->runtime()->handle($this->request($workspace, null));

        $this->assertSame(AiResponseStatus::Failed, $response->status);
        $this->assertSame(AiRuntimeStage::ResolveEmployee, $response->decision->failure?->stage);
        $this->assertSame(AiFailureCode::DependencyFailed, $response->decision->failure?->code);
        $this->assertSame([], $harness->model->requests);
    }

    public function test_preview_may_run_a_requested_inactive_employee_but_live_may_not(): void
    {
        $workspace = $this->workspace();
        $default = $this->employee($workspace);
        $inactive = app(EmployeeService::class)->deactivate($this->employee($workspace, ['name' => 'Omar']));
        $conversation = $this->conversation($workspace, null);

        $this->assertSame((int) $inactive->id, $this->resolver()->resolve($this->request($workspace, null, AiRunMode::Preview, (int) $inactive->id))->employeeId);
        $this->assertSame((int) $default->id, $this->resolver()->resolve($this->request($workspace, $conversation->id, AiRunMode::Live, (int) $inactive->id))->employeeId);
    }

    public function test_resolved_employee_is_rendered_into_the_employee_layer_below_the_constitution(): void
    {
        $workspace = $this->workspace();
        $employee = $this->employee($workspace, ['name' => 'Omar', 'instructions' => 'Always mention delivery times.']);

        $harness = new AiRuntimeHarness;
        $harness->employees = $this->resolver();
        $harness->constitution = new ConstitutionLoader;
        $harness->model->script = [new ModelResponse('أهلا')];
        $response = $harness->runtime()->handle($this->request($workspace, null, AiRunMode::Preview));

        $prompt = $harness->model->requests[0]->prompt;
        $layers = array_column($prompt->toArray(), 'layer');
        $employeeText = implode("\n", array_map(static fn (PromptSection $s): string => $s->content, $prompt->forLayer(PromptLayer::Employee)));
        $this->assertStringContainsString('Omar', $employeeText);
        $this->assertStringContainsString('Always mention delivery times.', $employeeText);
        $this->assertLessThan(array_search('employee', $layers, true), array_search('constitution', $layers, true));
        $this->assertSame((int) $employee->id, $response->decision->employeeId);
        $this->assertSame('1', $response->decision->employeeConfigVersion);
    }

    public function test_binder_assigns_only_unassigned_conversations_of_the_same_workspace(): void
    {
        $workspace = $this->workspace();
        $first = $this->employee($workspace);
        $second = $this->employee($workspace, ['name' => 'Omar']);
        $other = $this->employee($this->workspace('Other'));
        $conversation = $this->conversation($workspace, null);
        $binder = app(ConversationEmployeeBinder::class);

        $this->assertFalse($binder->bindIfUnassigned((int) $workspace->id, (int) $conversation->id, (int) $other->id));
        $this->assertTrue($binder->bindIfUnassigned((int) $workspace->id, (int) $conversation->id, (int) $first->id));
        $this->assertFalse($binder->bindIfUnassigned((int) $workspace->id, (int) $conversation->id, (int) $second->id));
        $this->assertSame((int) $first->id, (int) Conversation::withoutGlobalScopes()->whereKey($conversation->id)->value('ai_employee_id'));
    }

    private function resolver(): DatabaseEmployeeResolver
    {
        return app(DatabaseEmployeeResolver::class);
    }

    private function request(Workspace $workspace, ?int $conversationId, AiRunMode $mode = AiRunMode::Live, ?int $employeeId = null): AiRequest
    {
        $mode = $conversationId === null && $mode === AiRunMode::Live ? AiRunMode::Preview : $mode;

        return new AiRequest((int) $workspace->id, $conversationId, $employeeId, new AiInboundMessage('مرحبا'), $mode);
    }

    private function conversation(Workspace $workspace, ?int $employeeId): Conversation
    {
        $conversation = Conversation::factory()->create(['workspace_id' => $workspace->id]);
        if ($employeeId !== null) {
            Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update(['ai_employee_id' => $employeeId]);
        }

        return $conversation;
    }

    /**
     * @param  list<PromptSection>  $sections
     */
    private function employeeName(array $sections): string
    {
        foreach ($sections as $section) {
            if ($section->key === 'employee.identity' && preg_match('/Name: (.+)/', $section->content, $match) === 1) {
                return trim($match[1]);
            }
        }

        return '';
    }
}
