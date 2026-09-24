<?php

namespace Tests\Unit\AI\Inbox\Security;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\AiSetting;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\Security\InboxAgentSecurityPolicy;
use App\Services\AI\Inbox\Security\InboxAgentToolRunBudget;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\AI\Inbox\Tools\InboxAgentToolCall;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutionResult;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutor;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\InboxAgentToolSecurityProfile;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InboxAgentArchitectureEchoTool;
use Tests\TestCase;

class InboxAgentToolSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(InboxAgentToolRegistry::class, new InboxAgentToolRegistry);
    }

    public function test_unknown_tool_is_rejected(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = $this->buildContext($workspace, $conversation, $message, $setting);

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            'missing_tool',
            'c1',
            ['query' => 'x'],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('unknown_tool', $result->errorCode);
    }

    public function test_forbidden_customer_id_argument_is_rejected(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = $this->buildContext($workspace, $conversation, $message, $setting);
        app(InboxAgentToolRegistry::class)->register(app(SearchProductsTool::class));

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            'search_products',
            'c1',
            ['query' => 'shoes', 'customer_id' => 99999],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('forbidden_identity_argument', $result->errorCode);
    }

    public function test_high_risk_write_tool_requires_confirmation(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = $this->buildContext($workspace, $conversation, $message, $setting);

        $registry = app(InboxAgentToolRegistry::class);
        $registry->register(new class implements InboxAgentSecuredTool
        {
            public function name(): string
            {
                return 'future_refund';
            }

            public function description(): string
            {
                return 'future';
            }

            public function securityProfile(): InboxAgentToolSecurityProfile
            {
                return new InboxAgentToolSecurityProfile(
                    InboxAgentToolRiskLevel::High,
                    InboxAgentToolAccessMode::Write,
                    requiresConfirmation: true,
                    maxCallsPerRun: 1,
                );
            }

            public function inputSchema(): array
            {
                return ['amount' => ['type' => 'integer', 'required' => true]];
            }

            public function execute($context, array $arguments): InboxAgentToolExecutionResult
            {
                return InboxAgentToolExecutionResult::success($this->name(), 'x', []);
            }
        });

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            'future_refund',
            'c1',
            ['amount' => 1],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('confirmation_required', $result->errorCode);
    }

    public function test_duplicate_tool_calls_are_limited(): void
    {
        config(['ai.inbox_agent.security.max_duplicate_tool_calls' => 1]);
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = $this->buildContext($workspace, $conversation, $message, $setting);
        app(InboxAgentToolRegistry::class)->register(new InboxAgentArchitectureEchoTool);
        $executor = app(InboxAgentToolExecutor::class);
        $budget = new InboxAgentToolRunBudget(app(InboxAgentSecurityPolicy::class));

        $call = new InboxAgentToolCall('architecture_echo', 'c1', ['message' => 'same']);
        $this->assertTrue($executor->executeOne($context, $call, $budget)->success);
        $result = $executor->executeOne($context, $call, $budget);
        $this->assertFalse($result->success);
        $this->assertSame('duplicate_tool_call', $result->errorCode);
    }

    public function test_search_products_remains_functional(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = $this->buildContext($workspace, $conversation, $message, $setting);
        app(InboxAgentToolRegistry::class)->register(app(SearchProductsTool::class));

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            'search_products',
            'c1',
            ['query' => 'cake'],
        ));

        $this->assertTrue($result->success);
    }

    public function test_system_prompt_does_not_include_api_key_config(): void
    {
        config(['services.google_ai_studio.key' => 'super-secret-test-key-value']);
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = $this->buildContext($workspace, $conversation, $message, $setting);

        $system = (string) $context->messages[0]['content'];
        $this->assertStringNotContainsString('super-secret-test-key-value', $system);
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: AiSetting}
     */
    private function seedFixtures(): array
    {
        $workspace = Workspace::factory()->create();
        $setting = AiSetting::factory()->create(['workspace_id' => $workspace->id]);
        $customer = Customer::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'ai_enabled' => true,
        ]);
        $message = Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'content' => 'محتاج كيكة عيد حب لغاية 120 ريال',
        ]);

        return [$workspace, $conversation, $message, $setting];
    }

    private function buildContext(Workspace $workspace, Conversation $conversation, Message $message, AiSetting $setting): InboxAgentContext
    {
        return app(WorkspaceContext::class)->runFor($workspace, fn () => app(InboxAgentContextBuilder::class)->build(
            $workspace,
            $conversation,
            $message,
            $setting,
        ));
    }
}
