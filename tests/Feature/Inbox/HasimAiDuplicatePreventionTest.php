<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Jobs\ProcessAIResponse;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\AI\Response\SmartResponseEngine;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class HasimAiDuplicatePreventionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. إرسال رسالة واحدة والتأكد من معالجة واحدة.
     */
    public function test_1_single_message_processed_once_and_generates_single_ai_reply(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Saud',
            'phone' => '966500000001',
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
            'content' => 'تقدر تقولي وش عندك كتب؟',
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('أكيد، خبرني شو تحتاج وبساعدك.');
        $this->app->instance(AIService::class, $ai);

        $job = new ProcessAIResponse($conversation->id, $inbound->id, $workspace->id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $aiReplies = Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->get();

        $this->assertCount(1, $aiReplies);
        $this->assertSame('أكيد، خبرني شو تحتاج وبساعدك.', $aiReplies->first()->content);
        $this->assertSame('ai_reply:'.$inbound->id, $aiReplies->first()->external_message_id);
    }

    /**
     * 2. استقبال Webhook نفسه 3 مرات والتأكد من معالجة واحدة.
     */
    public function test_2_same_webhook_received_three_times_processed_only_once(): void
    {
        Bus::fake([ProcessIncomingWhatsAppMessage::class]);

        $workspace = Workspace::factory()->create();

        $plan = Plan::query()->create([
            'code' => 'company_wa_test_4',
            'name' => 'WA Test 4',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['whatsapp', 'ai', 'conversations'],
            'limits' => ['whatsapp_messages' => 100],
        ]);

        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_dedup',
            'display_name' => 'Store WA',
            'status' => 'connected',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => 'pn_triple_webhook',
            'display_phone_number' => '+966500000002',
            'verified_name' => 'Store',
            'status' => 'connected',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba_dedup',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '966500000002',
                            'phone_number_id' => 'pn_triple_webhook',
                        ],
                        'messages' => [[
                            'from' => '966512345678',
                            'id' => 'wamid.REPLAY_3_TIMES',
                            'timestamp' => '1700000000',
                            'text' => ['body' => 'تقدر تقولي وش عندك كتب؟'],
                            'type' => 'text',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $service = app(WhatsAppService::class);

        // Receive the exact same webhook 3 times
        $service->processWebhook($payload, []);
        $service->processWebhook($payload, []);
        $service->processWebhook($payload, []);

        // Assert WebhookEvent was created only once
        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('external_event_id', 'wamid.REPLAY_3_TIMES')
            ->count());

        // Assert job dispatched only once
        Bus::assertDispatched(ProcessIncomingWhatsAppMessage::class, 1);
    }

    /**
     * 3. إعادة تشغيل Queue Job بعد إرسال الرد والتأكد من عدم تكراره.
     */
    public function test_3_queue_job_retry_after_reply_sent_does_not_duplicate_reply(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Fahad',
            'phone' => '966500000003',
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
            'content' => 'تقدر تقولي وش عندك كتب؟',
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('أكيد، خبرني شو تحتاج وبساعدك.');
        $this->app->instance(AIService::class, $ai);

        $job = new ProcessAIResponse($conversation->id, $inbound->id, $workspace->id);

        // First attempt: succeeds and stores outbound message
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

        // Queue worker retries job (e.g. timeout on subsequent network call)
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        // Assert reply was NOT duplicated
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('ai_generated', true)
            ->count());
    }

    /**
     * 4. انقطاع الاتصال بعد إرسال الرد: عدم إعادة إرسال الرسالة إلى العميل عند إعادة المحاولة.
     */
    public function test_4_connection_drop_after_send_prevents_duplicate_outbound_delivery(): void
    {
        config()->set('services.whatsapp.token', 'test-token');
        config()->set('whatsapp.api_version', 'v20.0');

        $workspace = Workspace::factory()->create();

        $plan = Plan::query()->create([
            'code' => 'company_wa_test_4',
            'name' => 'WA Test 4',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['whatsapp', 'ai', 'conversations'],
            'limits' => ['whatsapp_messages' => 100],
        ]);

        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_conn_drop',
            'display_name' => 'Store',
            'status' => 'connected',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => 'pn_conn_drop',
            'display_phone_number' => '+966500000004',
            'verified_name' => 'Store',
            'status' => 'connected',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'external_id' => '966599999999',
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
            'metadata' => [
                'phone_number_id' => 'pn_conn_drop',
                'channel_source' => 'whatsapp',
            ],
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'أكيد، خبرني شو تحتاج وبساعدك.',
            'ai_generated' => true,
        ]);

        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => 'whatsapp',
            'status' => ChannelOutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.GRAPH_SENT_1']],
            ], 200),
        ]);

        $outboundService = app(WhatsAppOutboundService::class);

        // 1st delivery attempt succeeds
        $outboundService->deliverTextForMessage($conversation, $message, $delivery);

        $this->assertSame(ChannelOutboundDelivery::STATUS_SENT, $delivery->fresh()->status);
        Http::assertSentCount(1);

        // Worker drops connection before queue job acknowledges, so delivery job runs again
        $outboundService->deliverTextForMessage($conversation, $message, $delivery->fresh());

        // Must NOT call Meta Graph API again
        Http::assertSentCount(1);
    }

    /**
     * 5. وصول رسالتين مختلفتين بسرعة: معالجة كل رسالة بسياقها الصحيح دون تكرار.
     */
    public function test_5_two_different_messages_arriving_rapidly_are_both_processed(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Tariq',
            'phone' => '966500000005',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $inbound1 = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'عندكم كتب تاريخ؟',
            'created_at' => now()->subSeconds(2),
        ]);

        $inbound2 = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'وكم سعر الشحن للرياض؟',
            'created_at' => now()->subSecond(),
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')
            ->with(Mockery::any(), Mockery::on(fn ($m) => $m->id === $inbound1->id))
            ->once()
            ->andReturn('نعم متوفرة كتب تاريخ.');
        $ai->shouldReceive('generateReply')
            ->with(Mockery::any(), Mockery::on(fn ($m) => $m->id === $inbound2->id))
            ->once()
            ->andReturn('الشحن للرياض بـ 25 ريال.');
        $this->app->instance(AIService::class, $ai);

        $job1 = new ProcessAIResponse($conversation->id, $inbound1->id, $workspace->id);
        $job1->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $job2 = new ProcessAIResponse($conversation->id, $inbound2->id, $workspace->id);
        $job2->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $replies = Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(2, $replies);
        $this->assertSame('نعم متوفرة كتب تاريخ.', $replies[0]->content);
        $this->assertSame('الشحن للرياض بـ 25 ريال.', $replies[1]->content);
    }

    /**
     * 6. عمل WebSocket و Polling في الوقت نفسه: التأكد من عدم إضافة الرسالة مرتين في الـ Frontend.
     */
    public function test_6_concurrent_duplicate_inbound_is_deduplicated_by_id(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Omar',
            'phone' => '966500000006',
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
            'content' => 'تقدر تقولي وش عندك كتب؟',
            'created_at' => now(),
        ]);

        // First AI response execution
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('أكيد، خبرني شو تحتاج وبساعدك.');
        $this->app->instance(AIService::class, $ai);

        $job = new ProcessAIResponse($conversation->id, $inbound->id, $workspace->id);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        // Immediate duplicate arrival (rapid retry within debounce window)
        $inboundDuplicate = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'تقدر تقولي وش عندك كتب؟',
            'created_at' => now(),
        ]);

        $jobDuplicate = new ProcessAIResponse($conversation->id, $inboundDuplicate->id, $workspace->id);
        $jobDuplicate->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        // AI reply should only be generated once
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->count());
    }

    /**
     * 7. إعادة إرسال العميل نفس السؤال عمداً بعد فترة: التعامل بذكاء دون تكرار الرد المتطابق آلياً.
     */
    public function test_7_customer_intentionally_repeating_same_question_does_not_repeat_verbatim_reply(): void
    {
        $workspace = Workspace::factory()->create();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Khaled',
            'phone' => '966500000007',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $priorInbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'تقدر تقولي وش عندك كتب؟',
            'created_at' => now()->subMinutes(5),
        ]);

        $priorAiReply = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'أكيد، خبرني شو تحتاج وبساعدك.',
            'ai_generated' => true,
            'external_message_id' => 'ai_reply:'.$priorInbound->id,
            'metadata' => ['in_reply_to_message_id' => $priorInbound->id],
            'created_at' => now()->subMinutes(5),
        ]);

        // Customer intentionally re-asks after 5 minutes
        $newInbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'تقدر تقولي وش عندك كتب؟',
            'created_at' => now(),
        ]);

        // If the AI model returns the exact same boilerplate reply
        $smartEngine = app(SmartResponseEngine::class);
        $diversified = $smartEngine->sanitizeReply(
            'أكيد، خبرني شو تحتاج وبساعدك.',
            $conversation,
            $newInbound,
        );

        // Assert Meshal diversified the response rather than repeating the identical canned words
        $this->assertNotSame('أكيد، خبرني شو تحتاج وبساعدك.', $diversified);
        $this->assertStringContainsString('أهلاً بك مجدداً', $diversified);
    }
}
