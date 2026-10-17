<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\AI\Core\AiFailureCode;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Employees\EmployeeService;
use App\AI\Evaluation\HasimEvaluator;
use App\AI\Inbox\HasimInboxRuntime;
use App\AI\Models\ModelGatewayException;
use App\Jobs\ProcessAIResponse;
use App\Models\AiLog;
use App\Models\Message;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Inbox\InboxHonestReplyFallback;
use App\Services\Channel\Outbound\ChannelOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\AI\Core\FakeResponseGuard;
use Tests\Support\AI\Core\FakeToolRunner;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class HasimInboxRuntimeTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_inbox_message_becomes_a_valid_live_ai_request(): void
    {
        [$workspace, $conversation, $message, $employee] = $this->seedHasimInbox();
        [$gateway] = $this->bindHasimRuntime();

        $result = app(HasimInboxRuntime::class)->reply($conversation, $message);

        $this->assertNotNull($result->request);
        $this->assertSame((int) $workspace->id, $result->request->workspaceId);
        $this->assertSame((int) $conversation->id, $result->request->conversationId);
        $this->assertNull($result->request->employeeId);
        $this->assertSame(AiRunMode::Live, $result->request->runMode);
        $this->assertSame((int) $message->id, $result->request->message->messageId);
        $this->assertSame('هل عندكم حذاء أسود؟', $result->request->message->text);
        $this->assertSame('web', $result->request->channel);
        $this->assertTrue($result->deliverable);
        $this->assertSame((int) $employee->id, $gateway->requests[0]->employeeId);
        $this->assertSame('user.message', $gateway->requests[0]->prompt->userMessage()?->key);
        $this->assertSame('هل عندكم حذاء أسود؟', $gateway->requests[0]->prompt->userMessage()?->content);
    }

    public function test_runtime_prompt_includes_constitution_training_and_structured_context(): void
    {
        [, $conversation, $message, $employee] = $this->seedHasimInbox();
        [$gateway] = $this->bindHasimRuntime();

        app(HasimInboxRuntime::class)->reply($conversation, $message);

        $keys = $gateway->requests[0]->prompt->sectionKeys();
        $layers = array_column($gateway->requests[0]->prompt->toArray(), 'layer');

        $this->assertContains('constitution.core', $keys);
        $this->assertNotFalse(array_search('constitution', $layers, true));
        $this->assertTrue(collect($keys)->contains(fn (string $key): bool => str_starts_with($key, 'training.')));
        $this->assertContains('context.intent', $keys);
        $this->assertContains('context.employee', $keys);
        $this->assertContains('context.state', $keys);
        $this->assertContains('context.customer', $keys);
        $this->assertLessThan(array_search('training', $layers, true), array_search('constitution', $layers, true));
        $this->assertLessThan(array_search('context', $layers, true), array_search('training', $layers, true));

        $employeeContext = $gateway->requests[0]->prompt->forLayer(PromptLayer::Context);
        $employeeJson = collect($employeeContext)->firstWhere(fn ($section) => $section->key === 'context.employee')?->content;
        $this->assertIsString($employeeJson);
        $this->assertStringContainsString((string) $employee->id, $employeeJson);
        $this->assertStringNotContainsString($employee->name, $employeeJson);
    }

    public function test_tools_execute_only_through_the_tool_runner(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $tools = new FakeToolRunner;
        [$gateway] = $this->bindHasimRuntime(
            tools: $tools,
            modelScript: [
                new ModelResponse(
                    null,
                    [new ModelToolCall('call_1', 'search_products', ['query' => 'حذاء'])],
                    ModelFinishReason::ToolCalls,
                ),
                new ModelResponse('أهلا، أقدر أساعدك في اختيار المنتج.'),
            ],
        );

        $result = app(HasimInboxRuntime::class)->reply($conversation, $message);

        $this->assertTrue($result->deliverable);
        $this->assertCount(1, $tools->executed);
        $this->assertSame('search_products', $tools->executed[0]->name);
        $this->assertCount(2, $gateway->requests);
        $this->assertNotSame([], $gateway->requests[0]->tools);
    }

    public function test_guard_is_applied_before_a_deliverable_reply(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $guard = new FakeResponseGuard;
        $this->bindHasimRuntime(guard: $guard);

        $result = app(HasimInboxRuntime::class)->reply($conversation, $message);

        $this->assertCount(1, $guard->inputs);
        $this->assertSame('أهلا، أقدر أساعدك في اختيار المنتج.', $guard->inputs[0]->text);
        $this->assertTrue($result->deliverable);
        $this->assertSame($guard->inputs[0]->text, $result->reply);
        $this->assertSame(GuardVerdict::Pass->value, $result->response?->decision->metadata['guard_verdict'] ?? null);
    }

    public function test_evaluation_runs_on_the_guarded_result(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $guard = new FakeResponseGuard;
        $guard->verdict = GuardVerdict::Repaired;
        $guard->repairedText = 'هلا، أقدر أساعدك.';
        $this->bindHasimRuntime(guard: $guard);

        $result = app(HasimInboxRuntime::class)->reply($conversation, $message);

        $this->assertTrue($result->deliverable);
        $this->assertSame('هلا، أقدر أساعدك.', $result->reply);
        $this->assertNotNull($result->evaluation);
        $this->assertSame(HasimEvaluator::EVALUATOR, $result->evaluation->evaluator);
        $this->assertSame('هلا، أقدر أساعدك.', $result->response?->text);
    }

    public function test_conversation_follows_the_current_default_employee_after_a_default_change(): void
    {
        [$workspace, $conversation, $message, $first, $customer] = $this->seedHasimInbox(['name' => 'Nora']);
        $second = $this->employee($workspace, ['name' => 'Omar']);
        app(EmployeeService::class)->setDefault($second);
        [$gateway] = $this->bindHasimRuntime();

        $this->assertNull($this->boundEmployeeId($conversation));

        $firstResult = app(HasimInboxRuntime::class)->reply($conversation, $message);
        $this->assertSame((int) $second->id, $firstResult->response?->decision->employeeId);
        $this->assertSame((int) $second->id, $this->boundEmployeeId($conversation));
        $this->assertSame((int) $second->id, $gateway->requests[0]->employeeId);

        app(EmployeeService::class)->rename($second, 'Renamed');
        app(EmployeeService::class)->setDefault($first);
        $followUp = $this->inboundFollowUp($conversation, $customer, 'والأسود؟');
        $gateway->script = [new ModelResponse('تمام، أقدر أساعد.')];

        $secondResult = app(HasimInboxRuntime::class)->reply($conversation->fresh() ?? $conversation, $followUp);

        $this->assertSame((int) $first->id, $secondResult->response?->decision->employeeId);
        $this->assertSame((int) $first->id, $this->boundEmployeeId($conversation));
        $this->assertSame((int) $first->id, $gateway->requests[1]->employeeId);
        $this->assertNotSame((int) $second->id, $this->boundEmployeeId($conversation));
        $this->assertSame('Renamed', $second->fresh()?->name);
        $this->assertSame('Nora', $first->fresh()?->name);
    }

    public function test_runtime_failure_returns_empty_reply_not_a_canned_success(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [new RuntimeException('model exploded')]);

        $result = app(HasimInboxRuntime::class)->reply($conversation, $message);

        $this->assertFalse($result->deliverable);
        $this->assertSame('', $result->reply);
        $this->assertSame(AiResponseStatus::Failed, $result->response?->status);
        $this->assertSame(AiFailureCode::ModelError, $result->response?->decision->failure?->code);
        $this->assertStringNotContainsString('تعذر إنشاء الرد', $result->reply);
    }

    public function test_model_gateway_exception_records_provider_codes_in_ai_log(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [
            ModelGatewayException::httpError('google_ai_studio', 503),
        ]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('', $reply);
        $log = AiLog::withoutGlobalScopes()->where('message_id', $message->id)->firstOrFail();
        $input = is_array($log->input_payload) ? $log->input_payload : [];
        $encoded = json_encode($input) ?: '';

        $this->assertSame('failed', $log->status);
        $this->assertSame('call_model', data_get($input, 'failure_stage'));
        $this->assertSame('model_error', data_get($input, 'failure_code'));
        $this->assertSame(ModelGatewayException::HTTP_ERROR, data_get($input, 'provider_error_code'));
        $this->assertSame(503, data_get($input, 'http_status'));
        $this->assertStringNotContainsString('sk-secret', $encoded);
    }

    public function test_model_gateway_exception_is_logged_with_safe_codes_only(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [
            new ModelGatewayException(ModelGatewayException::RATE_LIMITED, 'key=sk-secret-do-not-log', 429),
        ]);
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $result = app(HasimInboxRuntime::class)->reply($conversation, $message);
        $failure = $result->response?->decision->failure?->toArray() ?? [];
        $failedLogs = array_values(array_filter(
            $logs,
            static fn (MessageLogged $event): bool => $event->message === 'hasim.inbox.failed',
        ));

        $this->assertFalse($result->deliverable);
        $this->assertSame(AiFailureCode::ModelError, $result->response?->decision->failure?->code);
        $this->assertSame(ModelGatewayException::RATE_LIMITED, $failure['provider_error_code'] ?? null);
        $this->assertSame(429, $failure['http_status'] ?? null);
        $this->assertStringNotContainsString('sk-secret', json_encode($failure) ?: '');
        $this->assertNotEmpty($failedLogs);
        $this->assertSame($failure, $failedLogs[0]->context['failure'] ?? null);
        $this->assertStringNotContainsString('sk-secret', json_encode($failedLogs[0]->context) ?: '');
    }

    public function test_guard_rejection_is_not_sent_and_is_logged_as_failure(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $guard = new FakeResponseGuard;
        $guard->verdict = GuardVerdict::Rejected;
        $this->bindHasimRuntime(guard: $guard);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('', $reply);
        $this->assertCount(1, $guard->inputs);
        $log = AiLog::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertTrue((bool) data_get($log->input_payload, 'hasim_runtime'));
        $this->assertSame(AiFailureCode::GuardRejected->value, $log->error_message);
        $this->assertSame($this->boundEmployeeId($conversation), (int) $log->ai_employee_id);
    }

    public function test_failed_hasim_run_sends_honest_fallback_without_internal_errors(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [new RuntimeException('model exploded')]);

        $job = new ProcessAIResponse((int) $conversation->id, (int) $message->id, (int) $conversation->workspace_id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $outbound = (string) Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->value('content');

        $this->assertSame(InboxHonestReplyFallback::GENERIC, $outbound);
        $this->assertStringNotContainsString('model exploded', $outbound);
        $this->assertStringNotContainsString('RuntimeException', $outbound);
    }

    public function test_empty_inbound_text_does_not_invent_a_reply(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $message->content = '   ';
        $message->save();
        [$gateway] = $this->bindHasimRuntime();

        $result = app(HasimInboxRuntime::class)->reply($conversation, $message);

        $this->assertSame('', $result->reply);
        $this->assertFalse($result->deliverable);
        $this->assertSame([], $gateway->requests);
    }
}
