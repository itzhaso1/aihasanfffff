<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Enums\WorkerEngagementStatus;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Events\Realtime\MessageStatusUpdated;
use App\Exceptions\ChannelOutboundRetryableException;
use App\Jobs\SendChannelOutboundMessage;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Models\WorkspaceTelegramConnection;
use App\Models\WorkspaceTikTokConnection;
use App\Services\AI\AIService;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Channel\Outbound\Contracts\ChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\FacebookMessengerChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\InstagramChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\TelegramChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\TikTokChannelOutboundDriver;
use App\Services\Channel\Outbound\Drivers\WhatsAppChannelOutboundDriver;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class OutboundSendReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'inbox.realtime.broadcast_connection' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'services.whatsapp.token' => 'wa-test-token',
        ]);
    }

    public function test_mobile_send_propagates_correlation_id_transitions_pending_to_sent_and_allows_retry(): void
    {
        Event::fake([MessageCreated::class, MessageStatusUpdated::class, ConversationUpdated::class]);
        Queue::fake();

        [$user, $workspace, $conversation] = $this->workspaceWithChannel('whatsapp', [
            'phone_number_id' => 'pn-001',
        ]);
        Sanctum::actingAs($user);

        $correlationId = 'corr-mobile-wa-001';
        $res = $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'X-Correlation-Id' => $correlationId,
            'Idempotency-Key' => 'client-wa-001',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'مرحباً من التطبيق',
            'idempotency_key' => 'client-wa-001',
        ]);

        $res->assertCreated()
            ->assertHeader('X-Correlation-Id', $correlationId)
            ->assertJsonPath('data.delivery_status', 'pending')
            ->assertJsonPath('data.client_id', 'client-wa-001')
            ->assertJsonPath('data.correlation_id', $correlationId);

        $messageId = (int) $res->json('data.id');
        Event::assertDispatched(MessageCreated::class, function (MessageCreated $e) use ($correlationId, $messageId): bool {
            return $e->message->id === $messageId && $e->correlationId === $correlationId;
        });

        Queue::assertPushed(SendChannelOutboundMessage::class, function (SendChannelOutboundMessage $job) use ($correlationId): bool {
            return $job->correlationId === $correlationId;
        });

        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()
            ->where('message_id', $messageId)
            ->firstOrFail();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.sent.9001']],
            ], 200),
        ]);

        (new SendChannelOutboundMessage($delivery->id, $workspace->id, $correlationId))
            ->handle(app(ChannelOutboundService::class));

        $fresh = Message::withoutGlobalScopes()->findOrFail($messageId);
        $this->assertSame('sent', $fresh->delivery_status);
        $this->assertSame('wamid.sent.9001', $fresh->external_message_id);

        Event::assertDispatched(MessageStatusUpdated::class, function (MessageStatusUpdated $e) use ($messageId, $correlationId): bool {
            $payload = $e->broadcastWith();

            return $payload['id'] === $messageId
                && $payload['delivery_status'] === 'sent'
                && $payload['correlation_id'] === $correlationId;
        });
    }

    public function test_idempotent_retry_repairs_missing_outbound_delivery_without_creating_duplicate_message(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);
        Queue::fake();

        [$user, $workspace, $conversation] = $this->workspaceWithChannel('whatsapp', [
            'phone_number_id' => 'pn-repair',
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'user_id' => $user->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'رسالة مقطوعة قبل الإرسال',
            'delivery_status' => 'pending',
            'metadata' => ['idempotency_key' => 'repair-key-1', 'client_id' => 'repair-key-1'],
        ]);

        $this->assertDatabaseCount('channel_outbound_deliveries', 0);

        Sanctum::actingAs($user);
        $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'repair-key-1',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'رسالة مقطوعة قبل الإرسال',
            'idempotency_key' => 'repair-key-1',
        ])->assertCreated()
            ->assertJsonPath('data.id', $message->id);

        $this->assertSame(1, Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
        $this->assertDatabaseHas('channel_outbound_deliveries', [
            'message_id' => $message->id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(SendChannelOutboundMessage::class);
    }

    public function test_queue_outbound_exception_marks_message_failed_instead_of_leaving_pending(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        [, $workspace, $conversation] = $this->workspaceWithChannel('whatsapp', [
            'phone_number_id' => 'pn-ex',
        ]);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'فشل الجدولة',
            'delivery_status' => 'pending',
        ]);

        $throwingDriver = new class implements ChannelOutboundDriver
        {
            public function supports(string $channel): bool
            {
                return $channel === 'whatsapp';
            }

            public function queueOutbound(Conversation $conversation, Message $message): void
            {
                throw new \RuntimeException('queue_broker_down');
            }
        };

        $service = new ChannelOutboundService(
            $this->app->make(WhatsAppChannelOutboundDriver::class),
            $this->app->make(InstagramChannelOutboundDriver::class),
            $this->app->make(FacebookMessengerChannelOutboundDriver::class),
            $this->app->make(TikTokChannelOutboundDriver::class),
            $this->app->make(TelegramChannelOutboundDriver::class),
        );
        // Inject failing driver in front is tested via reflection/subclass; use custom subclass override cleanly:
        $ref = new \ReflectionProperty(ChannelOutboundService::class, 'drivers');
        $ref->setValue($service, [$throwingDriver]);

        $service->queueOutboundForMessage($conversation, $message);

        $fresh = $message->fresh();
        $this->assertSame('failed', $fresh->delivery_status);
        $this->assertSame('queue_broker_down', $fresh->metadata['delivery_error'] ?? null);
        $this->assertDatabaseHas('channel_outbound_deliveries', [
            'message_id' => $message->id,
            'status' => 'failed',
            'last_error' => 'queue_broker_down',
        ]);
        Event::assertDispatched(MessageStatusUpdated::class, fn (MessageStatusUpdated $e): bool => $e->message->id === $message->id && $e->message->delivery_status === 'failed');
    }

    public function test_retry_failed_outbound_message_resets_failed_to_pending_and_delivers_with_idor_protection(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        [$owner, $workspace, $conversation] = $this->workspaceWithChannel('whatsapp', [
            'phone_number_id' => 'pn-retry',
        ]);
        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_user_id' => $otherUser->id]);
        $otherWorkspace->users()->attach($otherUser->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'user_id' => $owner->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'رسالة تحتاج إعادة إرسال',
            'delivery_status' => 'failed',
            'metadata' => [
                'client_id' => 'retry-client-9',
                'delivery_error' => 'missing_token',
            ],
        ]);
        ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => 'whatsapp',
            'status' => ChannelOutboundDelivery::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'missing_token',
            'failed_at' => now(),
        ]);

        // Cross-workspace attempt must 404.
        Sanctum::actingAs($otherUser);
        $this->withHeaders(['X-Workspace-Id' => (string) $otherWorkspace->id])
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages/{$message->id}/retry")
            ->assertNotFound();

        Sanctum::actingAs($owner);
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.retried.77']],
            ], 200),
        ]);

        $retryRes = $this->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages/{$message->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.delivery_status', 'sent')
            ->assertJsonPath('data.delivery_error', null);

        $this->assertSame('sent', $retryRes->json('data.delivery_status'));
        $fresh = $message->fresh();
        $this->assertSame('sent', $fresh->delivery_status);
        $this->assertArrayNotHasKey('delivery_error', $fresh->metadata ?? []);
        $this->assertDatabaseHas('channel_outbound_deliveries', [
            'message_id' => $message->id,
            'status' => 'sent',
            'provider_message_id' => 'wamid.retried.77',
        ]);
    }

    public function test_all_external_channels_handle_connection_exceptions_as_retryable_and_4xx_as_failed(): void
    {
        Event::fake([MessageStatusUpdated::class]);
        [, $workspace] = $this->workspaceWithChannel('whatsapp', ['phone_number_id' => 'pn-multi']);
        config(['tiktok.api_base' => 'https://business-api.tiktok.com/open_api', 'telegram.api_base' => 'https://api.telegram.org']);

        $igConn = new WorkspaceMetaConnection([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::INSTAGRAM_USER,
            'external_id' => 'ig-acc-1',
            'status' => 'connected',
        ]);
        $igConn->storeAccessToken('ig-token');
        $igConn->save();

        $fbConn = new WorkspaceMetaConnection([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::FACEBOOK_PAGE,
            'external_id' => 'fb-page-1',
            'status' => 'connected',
        ]);
        $fbConn->storeAccessToken('fb-token');
        $fbConn->save();
        $tgConnection = new WorkspaceTelegramConnection([
            'workspace_id' => $workspace->id,
            'bot_user_id' => 'bot-1',
            'bot_username' => 'hasim_bot',
            'public_webhook_key' => 'tg-hook-1',
            'status' => WorkspaceTelegramConnection::STATUS_CONNECTED,
        ]);
        $tgConnection->storeWebhookSecret('tg-secret-1');
        $tgConnection->storeBotToken('tg-token');
        $tgConnection->save();
        $ttConn = new WorkspaceTikTokConnection([
            'workspace_id' => $workspace->id,
            'business_open_id' => 'tt-biz-1',
            'status' => WorkspaceTikTokConnection::STATUS_CONNECTED,
        ]);
        $ttConn->storeTokens('tt-token');
        $ttConn->save();

        $channels = [
            'whatsapp' => ['external_id' => '966500001111', 'metadata' => ['channel_source' => 'whatsapp', 'phone_number_id' => 'pn-multi']],
            'instagram' => ['external_id' => 'igsid-1', 'metadata' => ['channel_source' => 'instagram', 'instagram_account_id' => 'ig-acc-1', 'instagram_user_id' => 'igsid-1']],
            'facebook_messenger' => ['external_id' => 'psid-1', 'metadata' => ['channel_source' => 'facebook_messenger', 'facebook_page_id' => 'fb-page-1', 'facebook_psid' => 'psid-1']],
            'telegram' => ['external_id' => 'tg-1', 'metadata' => ['channel_source' => 'telegram', 'telegram_chat_id' => '123456']],
            'tiktok' => ['external_id' => 'tt-1', 'metadata' => ['channel_source' => 'tiktok', 'tiktok_business_open_id' => 'tt-biz-1', 'tiktok_conversation_id' => 'tt-conv-1']],
        ];

        $customer = Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();

        $mode = 'connection_error';
        Http::fake(function () use (&$mode) {
            if ($mode === 'connection_error') {
                throw new ConnectionException('Network timeout');
            }

            return Http::response([
                'ok' => false,
                'code' => 40001,
                'message' => 'Bad recipient',
                'description' => 'Bad Request: chat not found',
                'error' => ['message' => 'Invalid recipient'],
            ], 400);
        });

        foreach ($channels as $channel => $cfg) {
            $conv = Conversation::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => $channel,
                'external_id' => $cfg['external_id'],
                'status' => 'open',
                'metadata' => $cfg['metadata'],
            ]);
            $msg = Message::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conv->id,
                'customer_id' => $customer->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => "اختبار قناة {$channel}",
                'delivery_status' => 'pending',
            ]);
            $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conv->id,
                'message_id' => $msg->id,
                'channel' => $channel,
                'status' => ChannelOutboundDelivery::STATUS_QUEUED,
                'attempts' => 0,
            ]);

            $mode = 'connection_error';
            try {
                app(ChannelOutboundService::class)->deliverQueuedDelivery($delivery);
                $this->fail("Expected ChannelOutboundRetryableException for {$channel}");
            } catch (ChannelOutboundRetryableException) {
                $this->assertSame(ChannelOutboundDelivery::STATUS_QUEUED, $delivery->fresh()->status, "{$channel} should remain queued after ConnectionException");
            }

            // Now test permanent 400 rejection
            $mode = 'http_400';
            app(ChannelOutboundService::class)->deliverQueuedDelivery($delivery->fresh());
            $this->assertSame(ChannelOutboundDelivery::STATUS_FAILED, $delivery->fresh()->status, "{$channel} should mark failed on HTTP 400");
            $this->assertSame('failed', $msg->fresh()->delivery_status, "{$channel} message status should transition to failed");
        }
    }

    public function test_web_store_ai_api_and_idempotency_error_codes_behave_consistently(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);

        [$user, $workspace, $conversation] = $this->workspaceWithChannel('web', []);

        // 1. Web controller creates message with delivery_status = sent for local web channel
        $this->actingAs($user);
        $webRes = $this->withSession(['current_workspace_id' => $workspace->id])
            ->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson(route('workspace.conversations.messages.store', ['conversation' => $conversation->id]), [
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'content' => 'رد من لوحة الويب',
                'client_id' => 'web-client-1',
            ]);
        $webRes->assertOk()
            ->assertJsonPath('message.delivery_status', 'sent')
            ->assertJsonPath('message.client_id', 'web-client-1');

        // 2. /api/ai/reply routes through ConversationService so realtime events fire
        $inbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'كم السعر؟',
        ]);
        $aiMock = Mockery::mock(AIService::class);
        $aiMock->shouldReceive('generateReply')->once()->andReturn('السعر 99 ريال.');
        $this->app->instance(AIService::class, $aiMock);

        app(WorkspaceContext::class)->set($workspace);
        $aiRes = $this->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/ai/reply', [
                'conversation_id' => $conversation->id,
                'message_id' => $inbound->id,
            ]);
        $aiRes->assertOk()->assertJsonPath('data.reply', 'السعر 99 ريال.');
        Event::assertDispatched(MessageCreated::class, fn (MessageCreated $e): bool => (bool) $e->message->ai_generated);

        // 3. Conflict vs In-Flight 409 codes
        Sanctum::actingAs($user);
        $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'conflict-key-1',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'رسالة أولى',
            'idempotency_key' => 'conflict-key-1',
        ])->assertCreated();

        $conflictRes = $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'conflict-key-1',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'رسالة مختلفة تماماً',
            'idempotency_key' => 'conflict-key-1',
        ]);
        $conflictRes->assertStatus(409)->assertJsonPath('code', 'idempotency_key_conflict');

        // 4. 401 unauthenticated / 422 empty content
        auth()->forgetGuards();
        $this->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'unauth',
        ])->assertStatus(401);

        Sanctum::actingAs($user);
        $this->postJson(
            "/api/mobile/v1/conversations/{$conversation->id}/messages",
            ['content' => ''],
            [
                'X-Workspace-Id' => (string) $workspace->id,
                'Idempotency-Key' => 'validation-empty-key',
            ],
        )->assertStatus(422);
    }

    public function test_cors_preflight_allows_correlation_and_idempotency_headers_for_mobile_send(): void
    {
        config([
            'cors.allowed_origins' => ['http://localhost:5000'],
        ]);

        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5000',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type,x-workspace-id,idempotency-key,x-correlation-id',
        ])->options('/api/mobile/v1/conversations/1/messages');

        $response->assertStatus(204);
        $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers', ''));
        $this->assertStringContainsString('x-correlation-id', $allowed);
        $this->assertStringContainsString('idempotency-key', $allowed);
        $this->assertStringContainsString('x-workspace-id', $allowed);
    }

    public function test_polling_after_id_and_web_poll_return_status_updates_for_pending_human_messages(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.poll.status.1']]], 200),
        ]);
        config(['queue.default' => 'sync']);

        [$owner, $workspace, $conversation] = $this->workspaceWithChannel('whatsapp', [
            'phone_number_id' => 'pn-poll-status',
        ]);
        Sanctum::actingAs($owner);

        $send = $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'X-Correlation-Id' => 'corr-poll-status-1',
            'Idempotency-Key' => 'client-poll-status-1',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'رسالة ستتحول إلى مرسلة',
            'idempotency_key' => 'client-poll-status-1',
        ]);

        $send->assertCreated();
        $messageId = (int) $send->json('data.id');
        $this->assertSame('sent', Message::withoutGlobalScopes()->findOrFail($messageId)->delivery_status);

        // Incremental polling with after_id=$messageId returns no newer messages, yet reports status_updates.
        $pollMobile = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson("/api/mobile/v1/conversations/{$conversation->id}/messages?after_id={$messageId}");
        $pollMobile->assertOk()
            ->assertJsonPath('meta.count', 0)
            ->assertJsonPath('meta.status_updates.0.id', $messageId)
            ->assertJsonPath('meta.status_updates.0.client_id', 'client-poll-status-1')
            ->assertJsonPath('meta.status_updates.0.delivery_status', 'sent');

        // Web dashboard poll-messages endpoint returns status_updates as well.
        auth()->forgetGuards();
        $pollWeb = $this->actingAs($owner, 'web')
            ->withSession(['current_workspace_id' => $workspace->id])
            ->getJson("/workspace/conversations/{$conversation->id}/poll-messages?after_id={$messageId}&pending_ids={$messageId}");
        $pollWeb->assertOk()
            ->assertJsonPath('status_updates.0.id', $messageId)
            ->assertJsonPath('status_updates.0.client_id', 'client-poll-status-1')
            ->assertJsonPath('status_updates.0.delivery_status', 'sent');
    }

    public function test_worker_and_owner_deliver_to_whatsapp_and_preserve_client_id_under_sync_queue(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.sync.owner']]], 200)
                ->push(['messages' => [['id' => 'wamid.sync.worker.mobile']]], 200)
                ->push(['messages' => [['id' => 'wamid.sync.worker.web']]], 200),
        ]);
        config(['queue.default' => 'sync']);

        [$owner, $workspace, $conversation] = $this->workspaceWithChannel('whatsapp', [
            'phone_number_id' => 'pn-sync-human',
        ]);

        // 1. Owner sends via Mobile API under sync queue.
        Sanctum::actingAs($owner);
        $ownerRes = $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'client-sync-owner',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'رسالة المالك',
            'client_id' => 'client-sync-owner',
            'idempotency_key' => 'client-sync-owner',
        ]);
        $ownerRes->assertCreated()->assertJsonPath('data.client_id', 'client-sync-owner');
        $ownerMsg = Message::withoutGlobalScopes()->findOrFail((int) $ownerRes->json('data.id'));
        $this->assertSame('sent', $ownerMsg->delivery_status);

        // 2. Approved independent worker (not a workspace_user pivot member) sends under sync queue.
        $worker = User::factory()->create(['name' => 'موظف مستقل للاختبار']);
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => 'موظف مستقل للاختبار',
            'country_code' => WorkerProfile::COUNTRY_SAUDI_ARABIA,
            'nationality' => 'Saudi',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'senior',
        ]);
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Inbox Specialist',
            'created_by' => $owner->id,
            'active_at' => now(),
        ]);
        foreach (['inbox.messages.read', 'inbox.messages.send'] as $cap) {
            WorkerEngagementPermission::query()->create([
                'worker_engagement_id' => $engagement->id,
                'capability_key' => $cap,
                'allowed' => true,
            ]);
        }

        Sanctum::actingAs($worker);
        $workerMobileRes = $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'client-sync-worker-mobile',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'رسالة الموظف من التطبيق',
            'client_id' => 'client-sync-worker-mobile',
            'idempotency_key' => 'client-sync-worker-mobile',
        ]);
        $workerMobileRes->assertCreated()
            ->assertJsonPath('data.client_id', 'client-sync-worker-mobile');
        $workerMobileMsg = Message::withoutGlobalScopes()->findOrFail((int) $workerMobileRes->json('data.id'));
        $this->assertSame('sent', $workerMobileMsg->delivery_status);

        auth()->forgetGuards();
        $workerWebRes = $this->actingAs($worker, 'web')
            ->postJson("/workers/{$workspace->id}/inbox/{$conversation->id}/messages", [
                'content' => 'رسالة الموظف من الويب',
                'client_id' => 'client-sync-worker-web',
                'idempotency_key' => 'client-sync-worker-web',
            ]);
        $workerWebRes->assertOk()
            ->assertJsonPath('message.client_id', 'client-sync-worker-web');
        $workerWebMsg = Message::withoutGlobalScopes()->findOrFail((int) $workerWebRes->json('message.id'));
        $this->assertSame('sent', $workerWebMsg->delivery_status);

        Http::assertSentCount(3);
    }

    /**
     * @param  array<string, mixed>  $convMetadata
     * @return array{0: User, 1: Workspace, 2: Conversation}
     */
    private function workspaceWithChannel(string $channel, array $convMetadata): array
    {
        $owner = User::factory()->create(['name' => 'وكيل حاسم']);
        $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'code' => 'outbound_rel_'.uniqid(),
            'name' => 'Outbound Reliability',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'USD',
            'price' => 0,
            'is_active' => true,
            'features' => ['ai', 'whatsapp', 'conversations', 'smart_replies'],
            'limits' => ['ai_usage' => 1000, 'ai_tokens' => 100000, 'whatsapp_messages' => 1000],
        ]);
        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        if ($channel === 'whatsapp') {
            $waAccount = WhatsAppAccount::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'business_account_id' => 'waba_'.uniqid(),
                'display_name' => 'Test WA',
                'status' => 'connected',
            ]);
            WhatsAppPhoneNumber::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'whats_app_account_id' => $waAccount->id,
                'phone_number_id' => $convMetadata['phone_number_id'] ?? 'pn-default',
                'display_phone_number' => '+966500000001',
                'status' => 'connected',
            ]);
        }

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل موثوقية',
            'phone' => '966511112233',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'external_id' => '966511112233',
            'status' => 'open',
            'metadata' => array_merge(['channel_source' => $channel], $convMetadata),
        ]);

        return [$owner, $workspace, $conversation];
    }
}
