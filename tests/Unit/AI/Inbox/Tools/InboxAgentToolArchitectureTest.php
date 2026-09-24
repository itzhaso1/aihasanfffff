<?php

namespace Tests\Unit\AI\Inbox\Tools;

use App\Models\AiSetting;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\InboxAgentException;
use App\Services\AI\Inbox\InboxAgentOrchestrator;
use App\Services\AI\Inbox\Tools\InboxAgentProviderToolCallParser;
use App\Services\AI\Inbox\Tools\InboxAgentToolArgumentValidator;
use App\Services\AI\Inbox\Tools\InboxAgentToolCall;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutor;
use App\Services\AI\Inbox\Tools\InboxAgentToolMessageBuilder;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistryException;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAiProvider;
use Tests\Support\InboxAgentArchitectureEchoTool;
use Tests\Support\InboxAgentArchitectureFailingTool;
use Tests\Support\ToolRoundFakeAiProvider;
use Tests\TestCase;

class InboxAgentToolArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.inbox_agent.enabled' => true,
            'ai.inbox_agent.fallback_enabled' => true,
            'ai.inbox_agent.max_iterations' => 5,
            'ai.inbox_agent.max_tool_rounds' => 3,
            'ai.inbox_agent.timeout_seconds' => 60,
        ]);

        $this->app->instance(InboxAgentToolRegistry::class, new InboxAgentToolRegistry);
    }

    public function test_register_tool_and_resolve(): void
    {
        $registry = new InboxAgentToolRegistry;
        $tool = new InboxAgentArchitectureEchoTool;
        $registry->register($tool);

        $this->assertTrue($registry->has('architecture_echo'));
        $this->assertSame($tool, $registry->get('architecture_echo'));
    }

    public function test_duplicate_tool_registration_is_rejected(): void
    {
        $registry = new InboxAgentToolRegistry;
        $registry->register(new InboxAgentArchitectureEchoTool);

        $this->expectException(InboxAgentToolRegistryException::class);
        $registry->register(new InboxAgentArchitectureEchoTool);
    }

    public function test_unknown_tool_resolution_is_rejected(): void
    {
        $registry = new InboxAgentToolRegistry;

        $this->expectException(InboxAgentToolRegistryException::class);
        $registry->get('missing_tool');
    }

    public function test_definitions_are_deterministic(): void
    {
        $registry = new InboxAgentToolRegistry;
        $registry->register(new InboxAgentArchitectureEchoTool);

        $first = array_map(static fn ($definition): string => $definition->name, $registry->definitions());
        $second = array_map(static fn ($definition): string => $definition->name, $registry->definitions());

        $this->assertSame($first, $second);
        $this->assertSame(['architecture_echo'], $first);
    }

    public function test_valid_argument_validation(): void
    {
        $validator = new InboxAgentToolArgumentValidator;
        $validated = $validator->validate(
            (new InboxAgentArchitectureEchoTool)->inputSchema(),
            ['message' => 'hello'],
        );

        $this->assertSame(['message' => 'hello'], $validated);
    }

    public function test_missing_required_argument(): void
    {
        $validator = new InboxAgentToolArgumentValidator;

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate((new InboxAgentArchitectureEchoTool)->inputSchema(), []);
    }

    public function test_invalid_argument_type(): void
    {
        $validator = new InboxAgentToolArgumentValidator;

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate((new InboxAgentArchitectureEchoTool)->inputSchema(), ['message' => ['not' => 'string']]);
    }

    public function test_unknown_argument(): void
    {
        $validator = new InboxAgentToolArgumentValidator;

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate((new InboxAgentArchitectureEchoTool)->inputSchema(), ['message' => 'ok', 'extra' => 'nope']);
    }

    public function test_malformed_json_arguments(): void
    {
        $validator = new InboxAgentToolArgumentValidator;

        $this->expectException(\InvalidArgumentException::class);
        $validator->decodeArguments('{not-json');
    }

    public function test_successful_tool_execution(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        $registry = app(InboxAgentToolRegistry::class);
        $registry->register(new InboxAgentArchitectureEchoTool);

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            toolName: 'architecture_echo',
            callId: 'call_1',
            arguments: ['message' => 'ping'],
        ));

        $this->assertTrue($result->success);
        $this->assertSame('ping', $result->payload['message']);
        $this->assertArrayNotHasKey('workspace_id', $result->payload);
    }

    public function test_tool_execution_exception_is_controlled(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        app(InboxAgentToolRegistry::class)->register(new InboxAgentArchitectureFailingTool);

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            toolName: 'architecture_fail',
            callId: 'call_fail',
            arguments: ['trigger' => 'boom'],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('execution_error', $result->errorCode);
        $this->assertStringNotContainsString('secret-api-key', json_encode($result->toModelPayload(), JSON_UNESCAPED_UNICODE));
    }

    public function test_unknown_tool_returns_structured_failure_without_execution(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        app(InboxAgentToolRegistry::class)->register(new InboxAgentArchitectureEchoTool);

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            toolName: 'not_allowlisted',
            callId: 'call_x',
            arguments: ['message' => 'ignored'],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('unknown_tool', $result->errorCode);
    }

    public function test_multiple_tool_calls_in_one_round(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        app(InboxAgentToolRegistry::class)->register(new InboxAgentArchitectureEchoTool);

        $results = app(InboxAgentToolExecutor::class)->executeAll($context, [
            new InboxAgentToolCall('architecture_echo', 'c1', ['message' => 'one']),
            new InboxAgentToolCall('architecture_echo', 'c2', ['message' => 'two']),
        ]);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->success);
        $this->assertTrue($results[1]->success);
    }

    public function test_loop_executes_tool_round_then_returns_final_reply(): void
    {
        $fake = new ToolRoundFakeAiProvider;
        $fake->responses = [
            [
                'content' => '',
                'tokens_used' => 4,
                'tool_calls' => [
                    [
                        'name' => 'architecture_echo',
                        'call_id' => 'tc_1',
                        'arguments' => ['message' => 'round-1'],
                    ],
                ],
                'raw' => [],
            ],
            [
                'content' => 'final after tools',
                'tokens_used' => 6,
                'raw' => ['content' => 'final after tools'],
            ],
        ];

        $this->bindToolRoundProvider($fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('final after tools', $result->reply);
        $this->assertSame(2, $result->iterations);
        $this->assertSame(10, $result->tokensUsed);
        $this->assertSame(1, $result->metadata['agent_tool_rounds']);
        $this->assertSame(1, $result->metadata['agent_tool_calls_total']);
        $this->assertSame('succeeded', $result->metadata['agent_tool_outcome']);
        $this->assertSame(2, $fake->calls);
    }

    public function test_max_tool_rounds_protection(): void
    {
        config(['ai.inbox_agent.max_tool_rounds' => 1, 'ai.inbox_agent.max_iterations' => 5]);

        $fake = new ToolRoundFakeAiProvider;
        $fake->responses = [
            [
                'content' => '',
                'tokens_used' => 1,
                'tool_calls' => [['name' => 'architecture_echo', 'call_id' => '1', 'arguments' => ['message' => 'a']]],
                'raw' => [],
            ],
            [
                'content' => '',
                'tokens_used' => 1,
                'tool_calls' => [['name' => 'architecture_echo', 'call_id' => '2', 'arguments' => ['message' => 'b']]],
                'raw' => [],
            ],
        ];

        $this->bindToolRoundProvider($fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();

        $this->expectException(InboxAgentException::class);
        $this->expectExceptionMessage('max tool rounds');

        app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);
    }

    public function test_context_remains_immutable_after_tool_messages(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);
        $originalCount = count($context->messages);

        app(InboxAgentToolRegistry::class)->register(new InboxAgentArchitectureEchoTool);

        $results = app(InboxAgentToolExecutor::class)->executeAll($context, [
            new InboxAgentToolCall('architecture_echo', 'c1', ['message' => 'x']),
        ]);

        $updated = app(InboxAgentToolMessageBuilder::class)
            ->appendToolRound($context->messages, [
                new InboxAgentToolCall('architecture_echo', 'c1', ['message' => 'x']),
            ], $results);

        $this->assertCount($originalCount, $context->messages);
        $this->assertGreaterThan($originalCount, count($updated));
    }

    public function test_workspace_isolation_in_tool_execution(): void
    {
        [$workspaceA, $conversation, $message, $setting] = $this->seedFixtures();
        $workspaceB = Workspace::factory()->create();

        $context = app(InboxAgentContextBuilder::class)->build($workspaceA, $conversation, $message, $setting);

        app(InboxAgentToolRegistry::class)->register(new InboxAgentArchitectureEchoTool);

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            toolName: 'architecture_echo',
            callId: 'ws',
            arguments: [
                'message' => 'probe',
                'workspace_id' => $workspaceB->id,
            ],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('forbidden_identity_argument', $result->errorCode);
    }

    public function test_provider_without_tool_calls_keeps_single_shot_behavior(): void
    {
        $fake = new FakeAiProvider;
        $fake->content = 'plain reply';
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('plain reply', $result->reply);
        $this->assertSame(1, $fake->calls);
        $this->assertSame('none', $result->metadata['agent_tool_outcome']);
        $this->assertSame(0, $result->metadata['agent_tool_rounds']);
    }

    public function test_malformed_provider_tool_call_throws_controlled_agent_exception(): void
    {
        $parser = app(InboxAgentProviderToolCallParser::class);

        $this->expectException(InboxAgentException::class);
        $parser->parse([
            'content' => '',
            'tool_calls' => [
                ['arguments' => ['message' => 'x']],
            ],
        ]);
    }

    private function bindToolRoundProvider(ToolRoundFakeAiProvider $fake): void
    {
        app(InboxAgentToolRegistry::class)->register(new InboxAgentArchitectureEchoTool);
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: AiSetting}
     */
    private function seedFixtures(): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $plan = Plan::query()->create([
            'code' => 'inbox_tool_arch_'.uniqid(),
            'name' => 'Inbox Tool Arch',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'conversations', 'smart_replies'],
            'limits' => ['ai_usage' => 10000, 'ai_tokens' => 1000000],
        ]);

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan): array {
            Subscription::query()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
            ]);

            $customer = Customer::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Tool Test Customer',
                'phone' => '+966500000099',
                'whatsapp' => null,
                'email' => 'tool-test@example.com',
                'orders_count' => 0,
                'total_purchases' => 0,
                'notes' => null,
                'metadata' => [],
            ]);

            $conversation = Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'web',
                'external_id' => 'conv-tool-arch',
                'status' => 'open',
                'ai_enabled' => true,
                'metadata' => [],
            ]);

            $message = Message::query()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'architecture test',
                'ai_generated' => false,
                'metadata' => [],
            ]);

            ChatCatalogItem::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => 'حذاء أسود',
                'slug' => 'black-shoe-tool-arch',
                'sku' => 'SHOE-ARCH',
                'price' => 10,
                'currency' => 'USD',
                'stock' => 3,
                'is_active' => true,
                'item_kind' => 'product',
                'tangibility' => 'tangible',
            ]);

            $setting = AiSetting::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Assistant',
                'instructions' => 'كن مختصرًا.',
                'provider' => 'google_ai_studio',
                'model' => 'gemini-2.5-flash',
                'temperature' => 0.3,
                'max_tokens' => 512,
                'is_active' => true,
            ]);

            return [$workspace, $conversation, $message, $setting];
        });
    }
}
