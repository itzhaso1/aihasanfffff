<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Core;

use App\AI\Core\AiDirective;
use App\AI\Core\AiResponse;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Core\Decision\ToolCallRecord;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Models\ModelGatewayException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AI\Core\AiRuntimeHarness;
use Tests\TestCase;

class AiRuntimeTest extends TestCase
{
    /**
     * @return array<string, array{0: AiRunMode, 1: bool}>
     */
    public static function runModes(): array
    {
        return [
            'live' => [AiRunMode::Live, true],
            'preview' => [AiRunMode::Preview, false],
            'evaluation' => [AiRunMode::Evaluation, false],
        ];
    }

    #[DataProvider('runModes')]
    public function test_successful_orchestration_in_every_run_mode(AiRunMode $mode, bool $deliverable): void
    {
        $harness = new AiRuntimeHarness;
        $harness->model->script = [new ModelResponse('سعره 299 ريال', usage: new ModelUsage('provider-a', 'model-x', 40, 8))];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request($mode));

        $this->assertSame(AiResponseStatus::Replied, $response->status);
        $this->assertSame('سعره 299 ريال', $response->text);
        $this->assertSame($deliverable, $response->isDeliverable());
        $this->assertSame($mode, $response->decision->runMode);
        $this->assertSame($mode, $harness->model->requests[0]->runMode);
        $this->assertSame($mode, $harness->guard->inputs[0]->request->runMode);
    }

    public function test_decision_traces_every_layer_without_prompt_text(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->model->script = [new ModelResponse('ايه موجود', usage: new ModelUsage('provider-a', 'model-x', 40, 8))];

        $decision = $harness->runtime()->handle(AiRuntimeHarness::request())->decision;
        $json = json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame(7, $decision->employeeId);
        $this->assertSame('cfg-3', $decision->employeeConfigVersion);
        $this->assertSame('const-v1', $decision->constitutionVersion);
        $this->assertSame('rules/style', $decision->trainingVersions[0]->id);
        $this->assertSame('price_question', $decision->intent);
        $this->assertSame('42', $decision->resolvedReferences[0]->id);
        $this->assertSame('item:42.price', $decision->facts[0]->key);
        $this->assertSame(GuardVerdict::Pass, $decision->guardResults[0]->verdict);
        $this->assertSame(48, $decision->modelUsage?->totalTokens());
        $this->assertSame(21, $decision->metadata['conversation_id']);
        $this->assertSame(64, strlen((string) $decision->metadata['prompt_fingerprint']));
        foreach (['CONSTITUTION-LAYER', 'EMPLOYEE-LAYER', 'TRAINING-LAYER', 'CONTEXT-LAYER', 'عندكم ايفون'] as $promptText) {
            $this->assertStringNotContainsString($promptText, $json);
        }
    }

    public function test_model_receives_layers_in_precedence_order_and_offered_tools(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->model->script = [new ModelResponse('ايه موجود')];

        $harness->runtime()->handle(AiRuntimeHarness::request());
        $request = $harness->model->requests[0];

        $this->assertSame(7, $request->employeeId);
        $this->assertSame(
            ['constitution', 'policy', 'employee', 'training', 'context', 'user_message'],
            array_column($request->prompt->toArray(), 'layer'),
        );
        $this->assertSame('عندكم ايفون 15؟', $request->prompt->forLayer(PromptLayer::UserMessage)[0]->content);
        $this->assertSame(['search_products'], array_map(static fn ($tool): string => $tool->name, $request->tools));
    }

    public function test_tool_loop_feeds_results_back_and_records_facts_and_actions_from_tools_only(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->tools->confirmedAction = new ConfirmedAction('context_saved', 'customer:5');
        $harness->model->script = [
            new ModelResponse(null, [new ModelToolCall('c1', 'search_products', ['query' => 'ايفون 15'])], ModelFinishReason::ToolCalls, new ModelUsage(null, 'model-x', 30, 5)),
            new ModelResponse('ايه موجود وسعره 299 ريال', usage: new ModelUsage(null, 'model-x', 50, 9)),
        ];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());

        $this->assertSame(AiResponseStatus::Replied, $response->status);
        $this->assertCount(2, $harness->model->requests);
        $this->assertCount(1, $harness->model->requests[1]->toolExchanges);
        $this->assertSame(['count' => 1, 'products' => [['id' => 42, 'price' => 299]]], $harness->model->requests[1]->toolExchanges[0]->result);
        $this->assertSame('search_products', $response->decision->toolCalls[0]->toolName);
        $this->assertSame(['query' => 'ايفون 15'], $response->decision->toolCalls[0]->arguments);
        $this->assertSame(['item:42.price', 'item:42.stock'], array_map(static fn ($fact): string => $fact->key, $response->decision->facts));
        $this->assertSame('context_saved', $response->decision->confirmedActions[0]->type);
        $this->assertSame(94, $response->decision->modelUsage?->totalTokens());
        $this->assertSame(2, $response->decision->modelUsage?->calls);
        $this->assertSame(1, $response->decision->metadata['tool_rounds']);
        $this->assertSame(['item:42.price', 'item:42.stock'], array_map(static fn ($fact): string => $fact->key, $harness->guard->inputs[0]->facts));
    }

    public function test_tools_that_were_not_offered_are_never_executed(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->model->script = [
            new ModelResponse(null, [new ModelToolCall('c1', 'create_order', ['item' => 42])], ModelFinishReason::ToolCalls),
            new ModelResponse('ما اقدر اسوي الطلب من هنا'),
        ];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());

        $this->assertSame([], $harness->tools->executed);
        $this->assertFalse($response->decision->toolCalls[0]->success);
        $this->assertSame(ToolCallRecord::NOT_OFFERED, $response->decision->toolCalls[0]->errorCode);
        $this->assertSame([], $response->decision->confirmedActions);
    }

    public function test_tool_round_limit_fails_without_text(): void
    {
        $harness = new AiRuntimeHarness(maxToolRounds: 1);
        $call = new ModelToolCall('c1', 'search_products', ['query' => 'x']);
        $harness->model->script = [
            new ModelResponse(null, [$call], ModelFinishReason::ToolCalls),
            new ModelResponse(null, [new ModelToolCall('c2', 'search_products', ['query' => 'y'])], ModelFinishReason::ToolCalls),
        ];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());

        $this->assertFailed($response, 'run_tool', 'tool_rounds_exceeded');
        $this->assertCount(1, $response->decision->toolCalls);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function dependencyFailures(): array
    {
        return [
            'employee resolver' => ['employees', 'resolve_employee', 'dependency_failed'],
            'constitution' => ['constitution', 'load_constitution', 'dependency_failed'],
            'tool runner' => ['tools', 'run_tool', 'dependency_failed'],
        ];
    }

    #[DataProvider('dependencyFailures')]
    public function test_dependency_failure_returns_failed_without_leaking_the_message(string $dependency, string $stage, string $code): void
    {
        $harness = new AiRuntimeHarness;
        $harness->{$dependency}->throw = new RuntimeException('SQLSTATE[HY000] secret Bearer abc.def');
        $harness->model->script = [
            new ModelResponse(null, [new ModelToolCall('c1', 'search_products', ['query' => 'x'])], ModelFinishReason::ToolCalls),
        ];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());
        $json = json_encode($response->decision, JSON_THROW_ON_ERROR);

        $this->assertFailed($response, $stage, $code);
        $this->assertSame('RuntimeException', $response->decision->failure?->exceptionType);
        $this->assertStringNotContainsString('SQLSTATE', $json);
        $this->assertStringNotContainsString('abc.def', $json);
    }

    public function test_model_exception_fails_as_model_error(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->model->script = [new RuntimeException('provider down')];

        $this->assertFailed($harness->runtime()->handle(AiRuntimeHarness::request()), 'call_model', 'model_error');
    }

    public function test_model_gateway_exception_preserves_provider_codes_in_failure_summary(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->model->script = [
            new ModelGatewayException(
                ModelGatewayException::HTTP_ERROR,
                'provider body AIzaSy-secret must not appear in logs',
                503,
            ),
        ];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());
        $failure = $response->decision->failure?->toArray() ?? [];
        $encoded = json_encode($failure) ?: '';

        $this->assertFailed($response, 'call_model', 'model_error');
        $this->assertSame(ModelGatewayException::HTTP_ERROR, $failure['provider_error_code'] ?? null);
        $this->assertSame(503, $failure['http_status'] ?? null);
        $this->assertStringNotContainsString('AIzaSy-secret', $encoded);
    }

    /**
     * @return array<string, array{0: ModelResponse, 1: string}>
     */
    public static function unusableModelResponses(): array
    {
        return [
            'error finish' => [new ModelResponse('partial', finishReason: ModelFinishReason::Error), 'model_error'],
            'safety stop' => [new ModelResponse(null, finishReason: ModelFinishReason::Safety), 'model_safety_stop'],
            'empty text' => [new ModelResponse('   '), 'empty_model_response'],
        ];
    }

    #[DataProvider('unusableModelResponses')]
    public function test_unusable_model_output_fails_and_never_reaches_the_guard(ModelResponse $modelResponse, string $code): void
    {
        $harness = new AiRuntimeHarness;
        $harness->model->script = [$modelResponse];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());

        $this->assertFailed($response, 'call_model', $code);
        $this->assertSame([], $harness->guard->inputs);
    }

    public function test_guard_rejection_fails_without_text(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->guard->verdict = GuardVerdict::Rejected;
        $harness->model->script = [new ModelResponse('تم انشاء طلبك')];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());

        $this->assertFailed($response, 'guard_response', 'guard_rejected');
        $this->assertSame(GuardVerdict::Rejected, $response->decision->guardResults[0]->verdict);
    }

    public function test_guard_repair_replaces_the_model_text(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->guard->verdict = GuardVerdict::Repaired;
        $harness->guard->repairedText = 'سعره 299 ريال';
        $harness->model->script = [new ModelResponse('سعره 299 ريال.')];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());

        $this->assertSame(AiResponseStatus::Replied, $response->status);
        $this->assertSame('سعره 299 ريال', $response->text);
        $this->assertSame('repaired', $response->decision->metadata['guard_verdict']);
    }

    public function test_guard_exception_fails(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->guard->throw = true;
        $harness->model->script = [new ModelResponse('ايه موجود')];

        $this->assertFailed($harness->runtime()->handle(AiRuntimeHarness::request()), 'guard_response', 'dependency_failed');
    }

    public function test_skip_directive_returns_skipped_without_model_or_training(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->context->directive = AiDirective::Skip;

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());

        $this->assertSame(AiResponseStatus::Skipped, $response->status);
        $this->assertNull($response->text);
        $this->assertSame([], $harness->model->requests);
        $this->assertSame(0, $harness->training->calls);
    }

    public function test_hand_off_directive_is_reported_but_never_performed_by_core(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->context->directive = AiDirective::HandOff;

        $response = $harness->runtime()->handle(AiRuntimeHarness::request());

        $this->assertSame(AiResponseStatus::HandedOff, $response->status);
        $this->assertNull($response->text);
        $this->assertFalse($response->isDeliverable());
        $this->assertSame([], $response->decision->confirmedActions);
        $this->assertSame([], $harness->model->requests);
        $this->assertSame('hand_off', $response->decision->metadata['directive']);
    }

    /**
     * @return array<string, array{0: AiRunMode}>
     */
    public static function everyRunMode(): array
    {
        return array_map(static fn (array $row): array => [$row[0]], self::runModes());
    }

    #[DataProvider('everyRunMode')]
    public function test_core_has_no_outbound_or_persistence_side_effects(AiRunMode $mode): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Http::fake();
        Queue::fake();
        Bus::fake();
        Event::fake();
        Mail::fake();
        Notification::fake();

        $harness = new AiRuntimeHarness;
        $harness->model->script = [
            new ModelResponse(null, [new ModelToolCall('c1', 'search_products', ['query' => 'x'])], ModelFinishReason::ToolCalls),
            new ModelResponse('ايه موجود'),
        ];
        $harness->runtime()->handle(AiRuntimeHarness::request($mode));

        $failing = new AiRuntimeHarness;
        $failing->model->script = [new RuntimeException('down')];
        $failing->runtime()->handle(AiRuntimeHarness::request($mode));

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Event::assertNothingDispatched();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertSame(0, $queries);
    }

    private function assertFailed(AiResponse $response, string $stage, string $code): void
    {
        $this->assertSame(AiResponseStatus::Failed, $response->status);
        $this->assertNull($response->text);
        $this->assertFalse($response->isDeliverable());
        $this->assertSame($stage, $response->decision->failure?->stage->value);
        $this->assertSame($code, $response->decision->failure?->code->value);
    }
}
