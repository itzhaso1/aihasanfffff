<?php

namespace Tests\Unit\AI\Inbox;

use App\Exceptions\UsageLimitExceededException;
use App\Jobs\ProcessAIResponse;
use App\Models\AiLog;
use App\Models\AiSetting;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Inbox\InboxAgentContextBuilder;
use App\Services\AI\Inbox\InboxAgentException;
use App\Services\AI\Inbox\InboxAgentLoop;
use App\Services\AI\Inbox\InboxAgentOrchestrator;
use App\Services\AI\Inbox\InboxAgentResult;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Providers\GoogleAiStudioProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\FailOnceFakeAiProvider;
use Tests\Support\FakeAiProvider;
use Tests\TestCase;

class InboxAgentOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.inbox_agent.enabled' => true,
            'ai.inbox_agent.fallback_enabled' => true,
            'ai.inbox_agent.max_iterations' => 3,
            'ai.inbox_agent.timeout_seconds' => 60,
        ]);

        $this->app->instance(InboxAgentToolRegistry::class, new InboxAgentToolRegistry);
        Queue::fake();
    }

    public function test_orchestrator_returns_successful_result_structure(): void
    {
        $fake = $this->bindFakeProvider('رد الوكيل');

        [$workspace, $conversation, $message, $setting] = $this->seedInboxFixtures();

        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('success', $result->status);
        $this->assertSame('رد الوكيل', $result->reply);
        $this->assertSame(1, $result->iterations);
        $this->assertSame(10, $result->tokensUsed);
        $this->assertArrayHasKey('inbox_agent', $result->metadata);
        $this->assertTrue($result->metadata['inbox_agent']);
        $this->assertArrayHasKey('agent_latency_ms', $result->metadata);
        $this->assertSame(1, $fake->calls);
    }

    public function test_provider_failure_returns_failed_result_with_unknown_tokens(): void
    {
        $fake = $this->bindFakeProvider();
        $fake->throw = true;

        [$workspace, $conversation, $message, $setting] = $this->seedInboxFixtures();

        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('failed', $result->status);
        $this->assertSame('provider failed', $result->failureReason);
        $this->assertSame(1, $result->iterations);
        $this->assertNull($result->tokensUsed);
        $this->assertTrue($result->metadata['agent_tokens_unknown'] ?? false);
    }

    public function test_agent_failure_falls_back_to_legacy_generation(): void
    {
        $fake = new FailOnceFakeAiProvider;
        $fake->content = 'رد fallback';
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);

        [$workspace, $conversation, $message] = $this->seedInboxFixtures();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('رد fallback', $reply);
        $this->assertSame(2, $fake->calls);

        $logs = AiLog::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('failed', $logs[0]->status);
        $this->assertTrue((bool) data_get($logs[0]->input_payload, 'inbox_agent'));
        $this->assertSame('failed', data_get($logs[0]->input_payload, 'agent_status'));
        $this->assertSame('success', $logs[1]->status);
        $this->assertTrue((bool) data_get($logs[1]->input_payload, 'inbox_agent_fallback'));
    }

    public function test_agent_failure_without_fallback_does_not_call_legacy(): void
    {
        config(['ai.inbox_agent.fallback_enabled' => false]);

        $fake = $this->bindFakeProvider();
        $fake->throw = true;

        [$workspace, $conversation, $message] = $this->seedInboxFixtures();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('تعذر إنشاء الرد الآن. حاول مرة أخرى لاحقًا.', $reply);
        $this->assertSame(1, $fake->calls);

        $logs = AiLog::withoutGlobalScopes()->get();
        $this->assertCount(1, $logs);
        $this->assertSame('failed', $logs[0]->status);
        $this->assertTrue((bool) data_get($logs[0]->input_payload, 'inbox_agent'));
        $this->assertSame('failed', data_get($logs[0]->input_payload, 'agent_status'));
    }

    public function test_agent_exception_without_fallback_does_not_call_legacy(): void
    {
        config(['ai.inbox_agent.fallback_enabled' => false]);

        $loop = Mockery::mock(InboxAgentLoop::class);
        $loop->shouldReceive('run')->once()->andThrow(InboxAgentException::timeout(60));
        $this->app->instance(InboxAgentLoop::class, $loop);

        $fake = $this->bindFakeProvider('ignored');

        [$workspace, $conversation, $message] = $this->seedInboxFixtures();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('تعذر إنشاء الرد الآن. حاول مرة أخرى لاحقًا.', $reply);
        $this->assertSame(0, $fake->calls);

        $log = AiLog::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame('failed', data_get($log->input_payload, 'agent_status'));
        $this->assertTrue((bool) data_get($log->input_payload, 'agent_tokens_unknown'));
    }

    public function test_aiservice_uses_agent_path_when_enabled(): void
    {
        $fake = $this->bindFakeProvider('رد من المسار الجديد');

        [$workspace, $conversation, $message] = $this->seedInboxFixtures();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('رد من المسار الجديد', $reply);
        $this->assertSame(1, $fake->calls);

        $log = AiLog::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertTrue((bool) data_get($log->input_payload, 'inbox_agent'));
        $this->assertSame('success', $log->status);
        $this->assertSame(1, (int) data_get($log->input_payload, 'agent_iterations'));
    }

    public function test_legacy_path_when_agent_disabled(): void
    {
        config(['ai.inbox_agent.enabled' => false]);

        $fake = $this->bindFakeProvider('رد legacy');

        [$workspace, $conversation, $message] = $this->seedInboxFixtures();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('رد legacy', $reply);
        $this->assertSame(1, $fake->calls);

        $log = AiLog::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertFalse((bool) data_get($log->input_payload, 'inbox_agent', false));
    }

    public function test_max_iterations_exception_falls_back_when_enabled(): void
    {
        $loop = Mockery::mock(InboxAgentLoop::class);
        $loop->shouldReceive('run')->once()->andThrow(InboxAgentException::maxIterations(1));
        $this->app->instance(InboxAgentLoop::class, $loop);

        $fake = $this->bindFakeProvider('ignored');

        [$workspace, $conversation, $message] = $this->seedInboxFixtures();

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('ignored', $reply);
        $this->assertSame(1, $fake->calls);
    }

    public function test_workspace_isolation_for_agent_catalog_context(): void
    {
        $fake = $this->bindFakeProvider();

        [$workspaceA, $conversation, $message] = $this->seedInboxFixtures();

        ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'name' => 'منتج خارجي',
            'slug' => 'external-item',
            'sku' => 'EXT-1',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 1,
            'is_active' => true,
            'item_kind' => 'product',
            'tangibility' => 'tangible',
        ]);

        app(AIService::class)->generateReply($conversation, $message);

        $this->assertStringContainsString('حذاء أسود', $fake->capturedPrompt());
        $this->assertStringNotContainsString('منتج خارجي', $fake->capturedPrompt());
    }

    public function test_feature_limit_exceptions_are_not_swallowed_by_fallback(): void
    {
        $this->bindFakeProvider();

        [$workspace, $conversation, $message] = $this->seedInboxFixtures(aiUsageLimit: 0);

        $this->expectException(UsageLimitExceededException::class);

        app(AIService::class)->generateReply($conversation, $message);
    }

    public function test_process_ai_response_still_produces_single_reply(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Buyer',
            'phone' => '966500000010',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $inbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hello',
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('automated reply');
        $this->app->instance(AIService::class, $ai);

        $job = new ProcessAIResponse($conversation->id, $inbound->id, $workspace->id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('ai_generated', true)
            ->count());
    }

    public function test_malformed_empty_provider_reply_triggers_fallback(): void
    {
        $fake = $this->bindFakeProvider('');
        $fake->content = '';

        [$workspace, $conversation, $message] = $this->seedInboxFixtures();

        config(['ai.inbox_agent.fallback_enabled' => true]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('تعذر إنشاء رد مناسب حالياً.', $reply);
        $this->assertSame(2, $fake->calls);

        $logs = AiLog::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('failed', $logs[0]->status);
        $this->assertSame('success', $logs[1]->status);
    }

    public function test_malformed_provider_result_without_content_respects_fallback_setting(): void
    {
        $fake = new MalformedMissingContentFakeProvider;
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);

        [$workspace, $conversation, $message] = $this->seedInboxFixtures();

        config(['ai.inbox_agent.fallback_enabled' => false]);

        $reply = app(AIService::class)->generateReply($conversation, $message);

        $this->assertSame('تعذر إنشاء الرد الآن. حاول مرة أخرى لاحقًا.', $reply);
        $this->assertSame(1, $fake->calls);

        $log = AiLog::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('missing a content field', (string) data_get($log->input_payload, 'failure_reason'));
    }

    public function test_loop_enforces_minimum_of_one_iteration_from_config(): void
    {
        config(['ai.inbox_agent.max_iterations' => 0]);

        $fake = $this->bindFakeProvider('iteration-ok');

        [$workspace, $conversation, $message, $setting] = $this->seedInboxFixtures();

        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(1, $result->iterations);
        $this->assertSame(1, $fake->calls);
    }

    public function test_result_metadata_includes_provider_and_model(): void
    {
        $this->bindFakeProvider();

        [$workspace, $conversation, $message, $setting] = $this->seedInboxFixtures();

        $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

        $this->assertInstanceOf(InboxAgentResult::class, $result);
        $this->assertNotEmpty($result->metadata['provider']);
        $this->assertNotEmpty($result->metadata['model']);
    }

    public function test_context_with_messages_returns_new_immutable_instance(): void
    {
        [$workspace, $conversation, $message, $setting] = $this->seedInboxFixtures();

        $context = app(InboxAgentContextBuilder::class)->build($workspace, $conversation, $message, $setting);
        $toolMessages = array_merge($context->messages, [
            ['role' => 'assistant', 'content' => 'tool call'],
            ['role' => 'tool', 'content' => 'tool result'],
        ]);

        $next = $context->withMessages($toolMessages);

        $this->assertNotSame($context, $next);
        $this->assertCount(2, $context->messages);
        $this->assertCount(4, $next->messages);
        $this->assertSame($context->workspace->id, $next->workspace->id);
        $this->assertSame($context->providerName, $next->providerName);
    }

    private function bindFakeProvider(string $content = 'رد تجريبي'): FakeAiProvider|FailOnceFakeAiProvider|MalformedMissingContentFakeProvider
    {
        $fake = new FakeAiProvider;
        $fake->content = $content;
        $this->app->instance(GoogleAiStudioProvider::class, $fake);
        $this->app->instance(OpenAiProvider::class, $fake);

        return $fake;
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3?: AiSetting}
     */
    private function seedInboxFixtures(int $aiUsageLimit = 10000): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $plan = Plan::query()->create([
            'code' => 'inbox_agent_test_'.uniqid(),
            'name' => 'Inbox Agent Test',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'conversations', 'smart_replies'],
            'limits' => ['ai_usage' => $aiUsageLimit, 'ai_tokens' => 1000000],
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
                'name' => 'عميل تجريبي',
                'phone' => '+966500000001',
                'whatsapp' => null,
                'email' => 'customer@example.com',
                'orders_count' => 0,
                'total_purchases' => 0,
                'notes' => null,
                'metadata' => [],
            ]);

            $conversation = Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'web',
                'external_id' => 'conv-agent-test',
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
                'content' => 'هل عندكم حذاء أسود؟',
                'ai_generated' => false,
                'metadata' => [],
            ]);

            ChatCatalogItem::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'name' => 'حذاء أسود',
                'slug' => 'black-shoe-agent',
                'sku' => 'SHOE-BLACK',
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

class MalformedMissingContentFakeProvider extends FakeAiProvider
{
    public function generate(array $messages, string $model, float $temperature, int $maxTokens, array $toolDefinitions = []): array
    {
        $this->calls++;

        return [
            'tokens_used' => 5,
            'raw' => [],
        ];
    }
}
