<?php

namespace Tests\Unit\AI\Inbox;

use App\Models\AiLog;
use App\Models\AiSetting;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\InboxAgentException;
use App\Services\AI\Inbox\InboxAgentOrchestrator;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FailOnceFakeAiProvider;
use Tests\Support\FakeAiProvider;
use Tests\Support\InboxAgentArchitectureFailingTool;
use Tests\Support\ToolRoundFakeAiProvider;
use Tests\TestCase;

class InboxAgentPhase3LoopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.inbox_agent.enabled' => true,
            'ai.inbox_agent.fallback_enabled' => true,
            'ai.inbox_agent.tools_enabled' => true,
            'ai.inbox_agent.max_iterations' => 5,
            'ai.inbox_agent.max_tool_rounds' => 3,
        ]);

        $registry = new InboxAgentToolRegistry;
        $registry->register(app(SearchProductsTool::class));
        $this->app->instance(InboxAgentToolRegistry::class, $registry);
    }

    public function test_scenario_a_tool_round_then_final_answer(): void
    {
        $fake = new ToolRoundFakeAiProvider;
        $fake->responses = [
            [
                'content' => '',
                'tokens_used' => 4,
                'tool_calls' => [[
                    'name' => 'search_products',
                    'call_id' => 'tc_search_1',
                    'arguments' => ['query' => 'حذاء'],
                ]],
                'raw' => [],
            ],
            [
                'content' => 'لدينا حذاء أسود متوفر.',
                'tokens_used' => 6,
                'raw' => [],
            ],
        ];
        $this->bindProvider($fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('لدينا حذاء أسود متوفر.', $result->reply);
        $this->assertSame(2, $result->iterations);
        $this->assertSame('succeeded', $result->metadata['agent_tool_outcome']);
        $this->assertContains('search_products', $result->metadata['agent_tool_names']);
    }

    public function test_scenario_b_normal_answer_without_tools(): void
    {
        $fake = new FakeAiProvider;
        $fake->content = 'رد مباشر';
        $this->bindProvider($fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('none', $result->metadata['agent_tool_outcome']);
        $this->assertSame(1, $fake->calls);
    }

    public function test_scenario_c_empty_search_then_final_answer(): void
    {
        $fake = new ToolRoundFakeAiProvider;
        $fake->responses = [
            [
                'content' => '',
                'tokens_used' => 3,
                'tool_calls' => [[
                    'name' => 'search_products',
                    'call_id' => 'tc_empty',
                    'arguments' => ['query' => 'zzzz-not-found'],
                ]],
                'raw' => [],
            ],
            [
                'content' => 'لا يوجد منتج مطابق حالياً.',
                'tokens_used' => 5,
                'raw' => [],
            ],
        ];
        $this->bindProvider($fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('لا يوجد منتج مطابق حالياً.', $result->reply);
    }

    public function test_scenario_d_tool_failure_then_final_answer(): void
    {
        $registry = app(InboxAgentToolRegistry::class);
        $registry->register(new InboxAgentArchitectureFailingTool);

        $fake = new ToolRoundFakeAiProvider;
        $fake->responses = [
            [
                'content' => '',
                'tokens_used' => 3,
                'tool_calls' => [[
                    'name' => 'architecture_fail',
                    'call_id' => 'tc_fail',
                    'arguments' => ['trigger' => 'boom'],
                ]],
                'raw' => [],
            ],
            [
                'content' => 'تعذر تنفيذ البحث، حاول لاحقاً.',
                'tokens_used' => 5,
                'raw' => [],
            ],
        ];
        $this->bindProvider($fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('failed', $result->metadata['agent_tool_outcome']);
    }

    public function test_scenario_e_tool_round_limit_uses_agent_failure_semantics(): void
    {
        config(['ai.inbox_agent.max_tool_rounds' => 1]);

        $fake = new ToolRoundFakeAiProvider;
        $fake->responses = [
            [
                'content' => '',
                'tool_calls' => [['name' => 'search_products', 'call_id' => '1', 'arguments' => ['query' => 'a']]],
                'tokens_used' => 1,
                'raw' => [],
            ],
            [
                'content' => '',
                'tool_calls' => [['name' => 'search_products', 'call_id' => '2', 'arguments' => ['query' => 'b']]],
                'tokens_used' => 1,
                'raw' => [],
            ],
        ];
        $this->bindProvider($fake);

        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();

        $this->expectException(InboxAgentException::class);
        app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);
    }

    public function test_scenario_f_agent_failure_with_fallback_enabled(): void
    {
        $fake = new FailOnceFakeAiProvider;
        $fake->content = 'legacy fallback reply';
        $this->bindProvider($fake);

        [$workspace, $conversation, $message] = $this->seedFixtures();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('legacy fallback reply', $reply);
        $log = AiLog::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertTrue((bool) data_get($log->input_payload, 'inbox_agent_fallback'));
    }

    private function bindProvider(FakeAiProvider|ToolRoundFakeAiProvider|FailOnceFakeAiProvider $fake): void
    {
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
            'code' => 'phase3_'.uniqid(),
            'name' => 'Phase 3',
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
                'name' => 'Customer',
                'phone' => '+966500000060',
                'email' => 'phase3@example.com',
                'orders_count' => 0,
                'total_purchases' => 0,
                'metadata' => [],
            ]);

            $conversation = Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'web',
                'status' => 'open',
                'ai_enabled' => true,
            ]);

            $message = Message::query()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'هل عندكم حذاء؟',
                'ai_generated' => false,
            ]);

            ChatCatalogItem::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => 'حذاء أسود',
                'slug' => 'black-shoe-phase3',
                'sku' => 'SHOE-P3',
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
                'is_active' => true,
            ]);

            return [$workspace, $conversation, $message, $setting];
        });
    }
}
