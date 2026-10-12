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
use App\Services\AI\Inbox\Tools\InboxAgentProviderToolCallParser;
use App\Services\AI\Inbox\Tools\InboxAgentToolArgumentValidator;
use App\Services\AI\Inbox\Tools\InboxAgentToolCall;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutor;
use App\Services\AI\Inbox\Tools\InboxAgentToolMessageBuilder;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistryException;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InboxAgentArchitectureEchoTool;
use Tests\Support\InboxAgentArchitectureFailingTool;
use Tests\TestCase;

class InboxAgentToolArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.inbox_agent.tools_enabled' => true,
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
