<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Inbox;

use App\AI\Context\AiContextBuilder;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Guard\Checks\TruthCheck;
use App\AI\Tools\Adapters\InboxToolContextFactory;
use App\Jobs\ProcessAIResponse;
use App\Jobs\ProcessIncomingInstagramMessage;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\ChatCatalog;
use App\Models\Message;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Inbox\InboxHonestReplyFallback;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Chat\ChatCatalogRootService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

class AiAutoReplyWithoutCatalogTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_auto_reply_answers_a_services_question_without_a_catalog(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->assertSame(0, ChatCatalog::withoutGlobalScopes()->where('workspace_id', $conversation->workspace_id)->count());
        $this->bindHasimRuntime(modelScript: [new ModelResponse('أكيد، خبرني شو نوع الخدمة اللي بتدور عليها وبساعدك.')]);
        Message::withoutGlobalScopes()->whereKey($message->id)->update([
            'content' => 'شو الخدمات المتوفرة عندكم؟',
        ]);

        $this->runAiJob($conversation->id, $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            'أكيد، خبرني شو نوع الخدمة اللي بتدور عليها وبساعدك.',
            (string) Message::withoutGlobalScopes()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('ai_generated', true)
                ->value('content'),
        );
    }

    public function test_auto_reply_answers_a_greeting_without_a_catalog(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [new ModelResponse('هلا، تفضل كيف أقدر أساعدك؟')]);
        Message::withoutGlobalScopes()->whereKey($message->id)->update(['content' => 'مرحبا']);

        $this->runAiJob($conversation->id, $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            'هلا، تفضل كيف أقدر أساعدك؟',
            (string) Message::withoutGlobalScopes()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('ai_generated', true)
                ->value('content'),
        );
    }

    public function test_empty_generation_still_sends_an_honest_fallback(): void
    {
        [, $conversation, $message] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [new \RuntimeException('gateway down')]);
        Message::withoutGlobalScopes()->whereKey($message->id)->update([
            'content' => 'شو الخدمات المتوفرة عندكم؟',
        ]);

        $this->runAiJob($conversation->id, $message->id, (int) $conversation->workspace_id);

        $this->assertSame(
            InboxHonestReplyFallback::OFFERINGS,
            (string) Message::withoutGlobalScopes()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('ai_generated', true)
                ->value('content'),
        );
    }

    public function test_search_products_stays_empty_when_leftover_inventory_exists(): void
    {
        [$workspace, $conversation, $message] = $this->seedHasimInbox();
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(ChatCatalogService::class)->createItem($workspace, [
                'name' => 'تنظيف منزل',
                'price' => '120.00',
                'currency' => 'SAR',
                'item_kind' => 'service',
            ]);
            $roots = app(ChatCatalogRootService::class);
            $catalog = $roots->forWorkspace($workspace);
            $roots->draft($catalog);
            $catalog->update([
                'channel_visibility' => [
                    'chat' => false,
                    'whatsapp' => false,
                    'instagram' => false,
                    'web' => false,
                    'marketplace' => false,
                ],
                'customer_service_policy' => ['inquiry_mode' => 'disabled'],
            ]);
        });

        $request = new AiRequest(
            workspaceId: (int) $workspace->id,
            conversationId: (int) $conversation->id,
            employeeId: null,
            message: new AiInboundMessage((string) $message->content, (int) $message->id),
            runMode: AiRunMode::Live,
            channel: (string) $conversation->channel,
        );
        $context = app(InboxToolContextFactory::class)->make($request);
        $result = app(SearchProductsTool::class)->execute($context, ['query' => 'شو الخدمات المتوفرة عندكم؟']);

        $policy = app(AiContextBuilder::class)->build(
            $request,
            new ResolvedEmployee(employeeId: 1, configVersion: '1', skillKeys: ['sales']),
        )->workspacePolicyContext;

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->payload['count']);
        $this->assertSame([], $result->payload['products']);
        $this->assertFalse($policy['catalog_required_for_reply']);
        $this->assertSame([], $policy['customer_service_policy']);
    }

    public function test_ai_does_not_invent_prices_without_inventory_facts(): void
    {
        $finding = (new TruthCheck)->inspect(
            new GuardInput(
                request: new AiRequest(
                    workspaceId: 1,
                    conversationId: 1,
                    employeeId: 1,
                    message: new AiInboundMessage('شو الخدمات المتوفرة عندكم؟'),
                    runMode: AiRunMode::Live,
                ),
                text: '',
                facts: [],
            ),
            'عندنا تنظيف مقابل 50 ريال',
        );

        $this->assertSame(GuardVerdict::Rejected, $finding->verdict);
        $this->assertSame('invented_price', $finding->reasonCode);
    }

    public function test_whatsapp_and_instagram_inbound_jobs_still_dispatch_ai_without_catalog(): void
    {
        $whatsapp = new ProcessIncomingWhatsAppMessage(7, ['id' => 'w1'], 11);
        $instagram = new ProcessIncomingInstagramMessage(7, ['id' => 'i1'], 12);

        $this->assertSame('inbound', $whatsapp->queue);
        $this->assertSame('inbound', $instagram->queue);
        $this->assertSame('whatsapp-inbound:7:11', $whatsapp->uniqueId());
        $this->assertSame('instagram-inbound:7:12', $instagram->uniqueId());
        $this->assertSame('ai', (new ProcessAIResponse(1, 2, 7))->queue);
    }

    private function runAiJob(int $conversationId, int $messageId, int $workspaceId): void
    {
        $job = new ProcessAIResponse($conversationId, $messageId, $workspaceId);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );
    }
}
