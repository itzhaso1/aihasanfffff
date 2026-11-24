<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\AI\Core\Data\ModelResponse;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Events\Realtime\MessageStatusUpdated;
use App\Jobs\ProcessAIResponse;
use App\Jobs\SendChannelOutboundMessage;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppOutboundMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Observability\CorrelationContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

final class RealtimeMessagingPipelineTest extends TestCase
{
    use CreatesHasimInboxFixtures;
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
        ]);
        require base_path('routes/channels.php');
    }

    public function test_core_realtime_events_use_immediate_should_broadcast_now(): void
    {
        $this->assertTrue(is_subclass_of(MessageCreated::class, ShouldBroadcastNow::class));
        $this->assertTrue(is_subclass_of(ConversationUpdated::class, ShouldBroadcastNow::class));
        $this->assertTrue(is_subclass_of(MessageStatusUpdated::class, ShouldBroadcastNow::class));
    }

    public function test_inbound_whatsapp_customer_message_persists_and_broadcasts_immediately_without_pii_logs(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);

        [$owner, $workspace] = $this->workspaceWithWhatsApp('pn-pipe-wa');
        $correlationId = 'corr-wa-inbound-001';
        CorrelationContext::set($correlationId);

        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        try {
            $started = microtime(true);
            app(WhatsAppService::class)->processWebhook([
                'entry' => [[
                    'changes' => [[
                        'value' => [
                            'metadata' => ['phone_number_id' => 'pn-pipe-wa'],
                            'messages' => [[
                                'id' => 'wamid.inbound.001',
                                'from' => '966501112233',
                                'type' => 'text',
                                'text' => ['body' => 'SECRET_CUSTOMER_BODY_TEXT'],
                            ]],
                        ],
                    ]],
                ]],
            ], []);
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        } finally {
            CorrelationContext::reset();
        }

        $this->assertLessThan(2000, $elapsedMs);

        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($correlationId) {
            $payload = $event->broadcastWith();

            return $payload['direction'] === 'inbound'
                && $payload['content'] === 'SECRET_CUSTOMER_BODY_TEXT'
                && ($payload['correlation_id'] ?? null) === $correlationId
                && ! empty($payload['broadcast_at']);
        });

        Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) {
            $payload = $event->broadcastWith();

            return isset($payload['last_message_data']['id'])
                && $payload['last_message_preview'] === 'SECRET_CUSTOMER_BODY_TEXT';
        });

        $serializedLogs = json_encode(array_map(fn ($e) => ['msg' => $e->message, 'ctx' => $e->context], $logs));
        $this->assertIsString($serializedLogs);
        $this->assertStringNotContainsString('SECRET_CUSTOMER_BODY_TEXT', $serializedLogs);
    }

    public function test_mobile_agent_send_commits_transaction_before_broadcast_and_respects_idempotency(): void
    {
        Queue::fake();
        config(['broadcasting.default' => 'null', 'inbox.realtime.broadcast_connection' => 'null']);

        [$owner, $workspace, $conversation] = $this->createWhatsAppConversation();
        $baselineLevel = DB::transactionLevel();
        $observed = [];

        Event::listen(MessageCreated::class, function (MessageCreated $event) use (&$observed, $baselineLevel): void {
            $observed[] = [
                'tx_level' => DB::transactionLevel(),
                'baseline' => $baselineLevel,
                'found_in_db' => Message::withoutGlobalScopes()->whereKey($event->message->id)->exists(),
                'client_id' => $event->broadcastWith()['client_id'] ?? null,
                'delivery_status' => $event->broadcastWith()['delivery_status'] ?? null,
            ];
        });

        Sanctum::actingAs($owner);

        $first = $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'client-opt-101',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'مرحبا من الموظف',
            'direction' => 'outbound',
            'message_type' => 'text',
        ]);

        $first->assertCreated()
            ->assertJsonPath('data.client_id', 'client-opt-101')
            ->assertJsonPath('data.delivery_status', 'pending');

        $second = $this->withHeaders([
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'client-opt-101',
        ])->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'مرحبا من الموظف',
            'direction' => 'outbound',
            'message_type' => 'text',
        ]);

        $second->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertCount(1, $observed);
        $this->assertSame($baselineLevel, $observed[0]['tx_level']);
        $this->assertTrue($observed[0]['found_in_db']);
        $this->assertSame('client-opt-101', $observed[0]['client_id']);
        $this->assertSame('pending', $observed[0]['delivery_status']);
        $this->assertSame(1, Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->count());
    }

    public function test_ai_reply_persists_and_broadcasts_immediately_after_commit(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);
        Queue::fake();

        [$workspace, $conversation, $inbound] = $this->seedHasimInbox();
        $this->bindHasimRuntime(modelScript: [new ModelResponse('أهلاً بك، تم تجهيز طلبك فوراً')]);
        Message::withoutGlobalScopes()->whereKey($inbound->id)->update(['content' => 'وين طلبي؟']);

        $corr = 'corr-ai-reply-77';
        $job = new ProcessAIResponse((int) $conversation->id, (int) $inbound->id, (int) $workspace->id, $corr);
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($corr) {
            $payload = $event->broadcastWith();

            return $payload['ai_generated'] === true
                && $payload['content'] === 'أهلاً بك، تم تجهيز طلبك فوراً'
                && ($payload['correlation_id'] ?? null) === $corr;
        });

        Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) {
            $payload = $event->broadcastWith();

            return $payload['last_message_preview'] === 'أهلاً بك، تم تجهيز طلبك فوراً'
                && ($payload['last_message_data']['ai_generated'] ?? false) === true;
        });
    }

    public function test_whatsapp_status_webhooks_update_delivery_and_read_monotonically(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        [, $workspace, $conversation] = $this->createWhatsAppConversation('pn-wa-status');
        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'رسالة متابعة',
            'delivery_status' => 'sent',
            'external_message_id' => 'wamid.status.track.1',
        ]);

        WhatsAppOutboundMessage::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_phone_number_id' => $phone->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'to' => '966501112233',
            'type' => 'text',
            'body' => 'رسالة متابعة',
            'provider_message_id' => 'wamid.status.track.1',
            'status' => WhatsAppOutboundMessage::STATUS_SENT,
        ]);

        $wa = app(WhatsAppService::class);
        $wa->processWebhook($this->statusWebhook('pn-wa-status', 'wamid.status.track.1', 'delivered', 1732490000), []);
        $this->assertSame('delivered', $message->fresh()->delivery_status);
        $this->assertNotNull($message->fresh()->delivered_at);

        $wa->processWebhook($this->statusWebhook('pn-wa-status', 'wamid.status.track.1', 'read', 1732490010), []);
        $this->assertSame('read', $message->fresh()->delivery_status);
        $this->assertNotNull($message->fresh()->read_at);

        // Stale out-of-order delivered webhook must NOT downgrade read back to delivered.
        $wa->processWebhook($this->statusWebhook('pn-wa-status', 'wamid.status.track.1', 'delivered', 1732490005), []);
        $this->assertSame('read', $message->fresh()->delivery_status);

        Event::assertDispatched(MessageStatusUpdated::class, 2);
    }

    public function test_outbound_delivery_permanent_failure_broadcasts_failed_message_status(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        [, $workspace, $conversation] = $this->createWhatsAppConversation('pn-wa-fail');
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'فشل محتمل',
            'delivery_status' => 'pending',
        ]);

        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => 'whatsapp',
            'idempotency_key' => 'wa-deliv-fail-1',
            'status' => ChannelOutboundDelivery::STATUS_QUEUED,
            'attempts' => 3,
        ]);

        $job = new SendChannelOutboundMessage($delivery->id, $workspace->id);
        $job->failed(new \RuntimeException('recipient_unreachable'));

        $this->assertSame('failed', $message->fresh()->delivery_status);
        Event::assertDispatched(MessageStatusUpdated::class, function (MessageStatusUpdated $event) use ($message) {
            return $event->message->id === $message->id
                && $event->broadcastWith()['delivery_status'] === 'failed'
                && $event->broadcastWith()['error_reason'] === 'recipient_unreachable';
        });
    }

    public function test_mobile_attachment_upload_broadcasts_updated_message_to_other_devices(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();
        config(['media.disk' => 'local']);

        [$owner, $workspace, $conversation] = $this->createWhatsAppConversation('pn-wa-att');
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => '📎',
            'user_id' => $owner->id,
            'metadata' => ['client_id' => 'att-key-9'],
        ]);

        Event::fake([MessageCreated::class, ConversationUpdated::class]);
        Sanctum::actingAs($owner);

        $file = UploadedFile::fake()->image('photo.jpg', 120, 120);
        $res = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->post("/api/mobile/v1/messages/{$message->id}/attachments", [
                'file' => $file,
            ]);

        $res->assertCreated();

        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($message) {
            $payload = $event->broadcastWith();

            return $payload['id'] === $message->id
                && $payload['client_id'] === 'att-key-9'
                && count($payload['attachments']) === 1
                && ! empty($payload['attachments'][0]['download_url']);
        });
    }

    public function test_incremental_after_id_updated_after_and_tenant_isolation(): void
    {
        [$owner, $workspace, $conversation] = $this->createWhatsAppConversation('pn-wa-inc');
        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_user_id' => $otherUser->id]);
        $otherWorkspace->users()->attach($otherUser->id, ['membership_role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

        $msgs = [];
        for ($i = 1; $i <= 5; $i++) {
            $msgs[] = Message::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'msg '.$i,
            ]);
        }

        Sanctum::actingAs($owner);

        // 1. Incremental after_id returns strictly newer messages in ascending order
        $catchUp = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson("/api/mobile/v1/conversations/{$conversation->id}/messages?after_id={$msgs[1]->id}");
        $catchUp->assertOk()
            ->assertJsonPath('meta.count', 3)
            ->assertJsonPath('meta.has_more', false);
        $ids = array_column($catchUp->json('data'), 'id');
        $this->assertSame([$msgs[2]->id, $msgs[3]->id, $msgs[4]->id], $ids);

        // 2. Delta conversations updated_after filters stale conversations cleanly
        $oldConv = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'web',
            'status' => 'open',
            'external_id' => 'old-conv',
            'last_message_at' => now()->subDays(2),
        ]);
        Conversation::withoutGlobalScopes()->whereKey($oldConv->id)->update([
            'updated_at' => now()->subDays(2),
        ]);
        $since = now()->subMinutes(5)->toIso8601String();
        $delta = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/conversations?updated_after='.urlencode($since));
        $delta->assertOk();
        $deltaIds = array_column($delta->json('data'), 'id');
        $this->assertContains($conversation->id, $deltaIds);
        $this->assertNotContains($oldConv->id, $deltaIds);

        // 3. Cross-tenant outsider receives 404/403
        Sanctum::actingAs($otherUser);
        $this->withHeader('X-Workspace-Id', (string) $otherWorkspace->id)
            ->getJson("/api/mobile/v1/conversations/{$conversation->id}/messages?after_id=1")
            ->assertNotFound();

        $this->withHeader('X-Workspace-Id', (string) $otherWorkspace->id)
            ->postJson('/api/mobile/v1/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-workspace.{$workspace->id}.conversation.{$conversation->id}",
            ])
            ->assertForbidden();
    }

    public function test_handoff_takeover_and_resume_ai_commit_before_single_coherent_broadcast(): void
    {
        Event::fake([ConversationUpdated::class]);
        [$owner, $workspace, $conversation] = $this->createWhatsAppConversation('pn-wa-handoff');

        $handoff = app(ConversationHandoffService::class);
        $handoff->takeover($conversation, $owner);
        Event::assertDispatched(ConversationUpdated::class, 1);

        $handoff->resumeAi($conversation->fresh(), $owner);
        Event::assertDispatched(ConversationUpdated::class, 2);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceWithWhatsApp(string $phoneNumberId): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $account = WhatsAppAccount::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_'.uniqid(),
            'display_name' => 'Pipe Test',
            'status' => 'connected',
        ]);

        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+966501112233',
            'status' => 'connected',
        ]);

        return [$owner, $workspace];
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Conversation}
     */
    private function createWhatsAppConversation(string $phoneNumberId = 'pn-pipe-wa'): array
    {
        [$owner, $workspace] = $this->workspaceWithWhatsApp($phoneNumberId);
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل تجريبي',
            'phone' => '966501112233',
            'whatsapp' => '966501112233',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
            'external_id' => '966501112233',
            'metadata' => ['phone_number_id' => $phoneNumberId, 'channel_source' => 'whatsapp'],
            'last_message_at' => now(),
        ]);

        return [$owner, $workspace, $conversation];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusWebhook(string $phoneNumberId, string $wamid, string $status, int $ts): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'statuses' => [[
                            'id' => $wamid,
                            'status' => $status,
                            'timestamp' => (string) $ts,
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
