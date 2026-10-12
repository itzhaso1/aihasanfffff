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
use App\Services\AI\Inbox\Tools\InboxAgentToolCall;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutor;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogRootService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SearchProductsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(InboxAgentToolRegistry::class, new InboxAgentToolRegistry);
    }

    public function test_search_products_is_registered_in_production_registry_when_enabled(): void
    {
        config(['ai.inbox_agent.tools_enabled' => true]);

        $registry = new InboxAgentToolRegistry;
        $registry->register(app(SearchProductsTool::class));

        $this->assertTrue($registry->has('search_products'));
    }

    public function test_definition_has_expected_schema(): void
    {
        $tool = app(SearchProductsTool::class);

        $this->assertSame('search_products', $tool->name());
        $this->assertArrayHasKey('query', $tool->inputSchema());
        $this->assertTrue($tool->inputSchema()['query']['required'] ?? false);
        $this->assertArrayHasKey('limit', $tool->inputSchema());
    }

    public function test_missing_query_is_rejected(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);
        app(InboxAgentToolRegistry::class)->register(app(SearchProductsTool::class));

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            toolName: 'search_products',
            callId: 'call_1',
            arguments: [],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('invalid_arguments', $result->errorCode);
    }

    public function test_invalid_query_type_is_rejected(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);
        app(InboxAgentToolRegistry::class)->register(app(SearchProductsTool::class));

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            toolName: 'search_products',
            callId: 'call_1',
            arguments: ['query' => ['bad']],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('invalid_arguments', $result->errorCode);
    }

    public function test_invalid_limit_is_rejected(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);
        app(InboxAgentToolRegistry::class)->register(app(SearchProductsTool::class));

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            toolName: 'search_products',
            callId: 'call_1',
            arguments: ['query' => 'shoe', 'limit' => 'many'],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('invalid_arguments', $result->errorCode);
    }

    public function test_limit_is_capped_to_safe_maximum(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        $result = app(SearchProductsTool::class)->execute($context, [
            'query' => 'هل عندكم فستان أسود؟',
            'limit' => 999,
        ]);

        $this->assertTrue($result->success);
        $this->assertLessThanOrEqual(SearchProductsTool::MAX_LIMIT, $result->payload['count']);
    }

    public function test_workspace_comes_from_context(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        $result = app(SearchProductsTool::class)->execute($context, ['query' => 'هل عندكم فستان أسود؟']);

        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(1, $result->payload['count']);
        $this->assertSame('فستان أسود', $result->payload['products'][0]['name']);
    }

    public function test_model_cannot_override_workspace(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $other = Workspace::factory()->create();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);
        app(InboxAgentToolRegistry::class)->register(app(SearchProductsTool::class));

        $result = app(InboxAgentToolExecutor::class)->executeOne($context, new InboxAgentToolCall(
            toolName: 'search_products',
            callId: 'call_1',
            arguments: ['query' => 'حذاء', 'workspace_id' => $other->id],
        ));

        $this->assertFalse($result->success);
        $this->assertSame('forbidden_identity_argument', $result->errorCode);
    }

    public function test_empty_search_result(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        $result = app(SearchProductsTool::class)->execute($context, ['query' => 'zzzz-not-found']);

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->payload['count']);
    }

    public function test_generic_product_listing_query_returns_catalog_items(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        $result = app(SearchProductsTool::class)->execute($context, ['query' => 'وش عندكم منتجات؟']);

        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(1, $result->payload['count']);
        $this->assertSame('فستان أسود', $result->payload['products'][0]['name']);
    }

    public function test_search_service_exception_returns_controlled_failure(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        $mock = Mockery::mock(ChatCatalogRetrievalService::class);
        $mock->shouldReceive('searchForPrompt')->andThrow(new \RuntimeException('SQLSTATE secret details'));
        $this->app->instance(ChatCatalogRetrievalService::class, $mock);
        $this->app->forgetInstance(SearchProductsTool::class);

        $result = app(SearchProductsTool::class)->execute($context, ['query' => 'shoe']);

        $this->assertFalse($result->success);
        $this->assertSame('search_failed', $result->errorCode);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($result->toModelPayload(), JSON_UNESCAPED_UNICODE));
    }

    public function test_sensitive_internal_fields_are_not_exposed(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        $result = app(SearchProductsTool::class)->execute($context, ['query' => 'هل عندكم فستان أسود؟']);
        $encoded = json_encode($result->toModelPayload(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('workspace_id', $encoded);
        $this->assertStringNotContainsString('SQL', $encoded);
        $this->assertArrayNotHasKey('catalog_item_id', $result->payload['products'][0]);
        $this->assertArrayHasKey('name', $result->payload['products'][0]);
    }

    public function test_search_products_reflects_live_catalog_updates_and_removals(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedFixtures();
        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);

        $first = app(SearchProductsTool::class)->execute($context, ['query' => 'فستان']);
        $this->assertTrue($first->success);
        $this->assertSame('فستان أسود', $first->payload['products'][0]['name']);

        app(WorkspaceContext::class)->runFor($workspace, function (): void {
            ChatCatalogItem::query()->where('sku', 'DRESS-SEARCH')->firstOrFail()->update([
                'name' => 'فستان أحمر محدّث',
                'description' => 'وصف جديد للمرجع',
                'price' => 99,
            ]);
        });

        $updated = app(SearchProductsTool::class)->execute($context, ['query' => 'فستان']);
        $this->assertTrue($updated->success);
        $this->assertSame('فستان أحمر محدّث', $updated->payload['products'][0]['name']);
        $this->assertSame('وصف جديد للمرجع', $updated->payload['products'][0]['description']);
        $this->assertSame(99, (int) $updated->payload['products'][0]['price']);

        app(WorkspaceContext::class)->runFor($workspace, function (): void {
            ChatCatalogItem::query()->where('sku', 'DRESS-SEARCH')->firstOrFail()->update([
                'is_active' => false,
                'archived_at' => now(),
            ]);
        });

        $removed = app(SearchProductsTool::class)->execute($context, ['query' => 'فستان']);
        $this->assertTrue($removed->success);
        $this->assertSame(0, $removed->payload['count']);
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: AiSetting}
     */
    private function seedFixtures(): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $plan = Plan::query()->create([
            'code' => 'search_products_'.uniqid(),
            'name' => 'Search Products',
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
                'phone' => '+966500000050',
                'email' => 'search@example.com',
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
                'content' => 'هل عندكم فستان؟',
                'ai_generated' => false,
            ]);

            ChatCatalogItem::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => 'فستان أسود',
                'slug' => 'black-dress-search',
                'sku' => 'DRESS-SEARCH',
                'price' => 10,
                'currency' => 'USD',
                'stock' => 3,
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'publication_status' => 'published',
                'item_kind' => 'product',
                'tangibility' => 'tangible',
            ]);
            $roots = app(ChatCatalogRootService::class);
            $roots->publish($roots->forWorkspace($workspace));

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
