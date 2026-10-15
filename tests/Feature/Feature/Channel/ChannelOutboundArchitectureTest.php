<?php

namespace Tests\Feature\Feature\Channel;

use App\Exceptions\ChannelOutboundRetryableException;
use App\Jobs\ProcessAIResponse;
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
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationService;
use App\Support\Meta\MetaConnectionKind;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ChannelOutboundArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_whatsapp_reply_queues_outbound_job_on_outbound_queue(): void
    {
        [$workspace, $user] = $this->workspaceWithWhatsApp();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'WA Buyer',
            'phone' => '966511111111',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'external_id' => '966511111111',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'whatsapp',
                'phone_number_id' => 'pnid_manual',
            ],
        ]);

        Http::fake();
        Queue::fake();

        app(WorkspaceContext::class)->runForId($workspace->id, function () use ($conversation, $user): void {
            app(ConversationService::class)->addMessage($conversation, [
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => 'Hello from inbox',
            ], $user);
        });

        Http::assertNothingSent();
        Queue::assertPushed(SendChannelOutboundMessage::class);

        $this->assertDatabaseHas('channel_outbound_deliveries', [
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'status' => 'queued',
        ]);

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->count());
    }

    public function test_manual_instagram_reply_dispatches_channel_outbound_job_not_http_inline(): void
    {
        [$workspace, , $user] = $this->workspaceWithInstagram('ig_acct_1');

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'IG Buyer',
            'phone' => 'ig:+10001',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'instagram',
            'external_id' => 'ig:+10001',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'instagram',
                'instagram_account_id' => 'ig_acct_1',
                'instagram_user_id' => 'igsid_999',
            ],
        ]);

        Http::fake();
        Queue::fake();

        app(WorkspaceContext::class)->runForId($workspace->id, function () use ($conversation, $user): void {
            app(ConversationService::class)->addMessage($conversation, [
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => 'IG manual reply',
            ], $user);
        });

        Http::assertNothingSent();
        Queue::assertPushed(SendChannelOutboundMessage::class);

        $this->assertDatabaseHas('channel_outbound_deliveries', [
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'status' => 'queued',
        ]);
    }

    public function test_instagram_outbound_job_persists_provider_message_id(): void
    {
        [$workspace] = $this->workspaceWithInstagram('ig_acct_2');

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'external_id' => 'ig:+20002',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'instagram',
                'instagram_account_id' => 'ig_acct_2',
                'instagram_user_id' => 'igsid_200',
            ],
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'Queued IG text',
        ]);

        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => 'instagram',
            'status' => ChannelOutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        Http::fake([
            'graph.instagram.com/*' => Http::response(['message_id' => 'mid.ig.test'], 200),
        ]);

        (new SendChannelOutboundMessage($delivery->id, $workspace->id))->handle(
            app(ChannelOutboundService::class),
        );

        $delivery->refresh();
        $message->refresh();

        $this->assertSame(ChannelOutboundDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame('mid.ig.test', $delivery->provider_message_id);
        $this->assertSame('mid.ig.test', $message->external_message_id);
    }

    public function test_instagram_non_retryable_failure_keeps_local_message_and_marks_delivery_failed(): void
    {
        [$workspace] = $this->workspaceWithInstagram('ig_acct_3');

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'external_id' => 'ig:+30003',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'instagram',
                'instagram_account_id' => 'ig_acct_3',
                'instagram_user_id' => 'igsid_300',
            ],
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'Will fail auth',
        ]);

        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => 'instagram',
            'status' => ChannelOutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        Http::fake([
            'graph.instagram.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth']], 400),
        ]);

        (new SendChannelOutboundMessage($delivery->id, $workspace->id))->handle(
            app(ChannelOutboundService::class),
        );

        $this->assertSame(1, Message::withoutGlobalScopes()->whereKey($message->id)->count());
        $this->assertSame(ChannelOutboundDelivery::STATUS_FAILED, $delivery->fresh()->status);
    }

    public function test_instagram_retryable_failure_throws_for_queue_retry(): void
    {
        [$workspace] = $this->workspaceWithInstagram('ig_acct_4');

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'external_id' => 'ig:+40004',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'instagram',
                'instagram_account_id' => 'ig_acct_4',
                'instagram_user_id' => 'igsid_400',
            ],
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'Retry me',
        ]);

        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => 'instagram',
            'status' => ChannelOutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        Http::fake([
            'graph.instagram.com/*' => Http::response(['error' => ['message' => 'Server error']], 503),
        ]);

        $this->expectException(ChannelOutboundRetryableException::class);

        (new SendChannelOutboundMessage($delivery->id, $workspace->id))->handle(
            app(ChannelOutboundService::class),
        );
    }

    public function test_ai_instagram_reply_does_not_call_provider_http_inline(): void
    {
        [$workspace] = $this->workspaceWithInstagram('ig_acct_ai');

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'IG AI',
            'phone' => 'ig:+50005',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'instagram',
            'external_id' => 'ig:+50005',
            'status' => 'open',
            'ai_enabled' => true,
            'metadata' => [
                'channel_source' => 'instagram',
                'instagram_account_id' => 'ig_acct_ai',
                'instagram_user_id' => 'igsid_ai',
            ],
        ]);

        $inbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'question',
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturn('AI answer');
        $this->app->instance(AIService::class, $ai);

        Http::fake();
        Queue::fake();

        (new ProcessAIResponse($conversation->id, $inbound->id, $workspace->id))->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        Http::assertNothingSent();
        Queue::assertPushed(SendChannelOutboundMessage::class);
    }

    public function test_duplicate_instagram_delivery_does_not_dispatch_second_job_when_already_sent(): void
    {
        [$workspace] = $this->workspaceWithInstagram('ig_acct_dup');

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'external_id' => 'ig:+60006',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'instagram',
                'instagram_account_id' => 'ig_acct_dup',
                'instagram_user_id' => 'igsid_dup',
            ],
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'Once',
        ]);

        ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => 'instagram',
            'status' => ChannelOutboundDelivery::STATUS_SENT,
            'attempts' => 1,
            'provider_message_id' => 'mid.sent',
            'sent_at' => now(),
        ]);

        Queue::fake();

        app(ChannelOutboundService::class)->queueOutboundForMessage($conversation, $message);

        Queue::assertNotPushed(SendChannelOutboundMessage::class);
    }

    public function test_send_channel_outbound_job_defines_retry_policy(): void
    {
        $job = new SendChannelOutboundMessage(1, 1);

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame([10, 45, 120], $job->backoff);
        $this->assertSame('outbound', $job->queue);
    }

    public function test_manual_facebook_reply_dispatches_channel_outbound_job(): void
    {
        $workspace = Workspace::factory()->create();
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'facebook_messenger',
            'external_id' => 'facebook:fb_user_1',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'facebook_messenger',
                'facebook_user_id' => 'fb_user_1',
                'facebook_page_id' => 'page_1',
            ],
        ]);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'FB hello',
        ]);

        Http::fake();
        Queue::fake();

        app(ChannelOutboundService::class)->queueOutboundForMessage($conversation, $message);

        Queue::assertPushed(SendChannelOutboundMessage::class);
        $this->assertDatabaseHas('channel_outbound_deliveries', [
            'workspace_id' => $workspace->id,
            'channel' => 'facebook_messenger',
            'status' => 'queued',
        ]);
    }

    public function test_facebook_delivery_is_sent_only_when_graph_returns_a_message_id(): void
    {
        [$workspace, $conversation, $message, $delivery] = $this->facebookQueuedDelivery();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'message_id' => 'mid.fb.ok',
                'recipient_id' => 'fb_user_1',
            ], 200),
        ]);

        (new SendChannelOutboundMessage($delivery->id, $workspace->id))->handle(
            app(ChannelOutboundService::class),
        );

        $this->assertSame(ChannelOutboundDelivery::STATUS_SENT, $delivery->fresh()?->status);
        $this->assertSame('mid.fb.ok', $delivery->fresh()?->provider_message_id);
        $this->assertSame('mid.fb.ok', $message->fresh()?->external_message_id);
    }

    public function test_facebook_graph_error_payload_is_not_marked_sent(): void
    {
        [$workspace, , , $delivery] = $this->facebookQueuedDelivery();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'blocked', 'code' => 10],
            ], 200),
        ]);

        (new SendChannelOutboundMessage($delivery->id, $workspace->id))->handle(
            app(ChannelOutboundService::class),
        );

        $this->assertSame(ChannelOutboundDelivery::STATUS_FAILED, $delivery->fresh()?->status);
        $this->assertSame('facebook_http_200', $delivery->fresh()?->last_error);
        $this->assertNull($delivery->fresh()?->sent_at);
    }

    public function test_facebook_success_without_message_id_is_not_marked_sent(): void
    {
        [$workspace, , , $delivery] = $this->facebookQueuedDelivery();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['recipient_id' => 'fb_user_1'], 200),
        ]);

        (new SendChannelOutboundMessage($delivery->id, $workspace->id))->handle(
            app(ChannelOutboundService::class),
        );

        $this->assertSame(ChannelOutboundDelivery::STATUS_FAILED, $delivery->fresh()?->status);
        $this->assertSame('missing_provider_message_id', $delivery->fresh()?->last_error);
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message, 3: ChannelOutboundDelivery}
     */
    private function facebookQueuedDelivery(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::FACEBOOK_PAGE,
            'external_id' => 'page_1',
            'display_name' => 'Test Page',
            'status' => 'connected',
            'scopes' => ['pages_messaging'],
            'metadata' => [
                'channel' => 'facebook_messenger',
                'graph_api_version' => 'v20.0',
            ],
            'connected_by_user_id' => $user->id,
        ]);
        $connection->storeAccessToken('page-access-token-test');
        $connection->save();

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'facebook_messenger',
            'external_id' => 'facebook:fb_user_1',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'facebook_messenger',
                'facebook_user_id' => 'fb_user_1',
                'facebook_page_id' => 'page_1',
            ],
        ]);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'FB hello',
        ]);
        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel' => 'facebook_messenger',
            'status' => ChannelOutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
        ]);

        return [$workspace, $conversation, $message, $delivery];
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function workspaceWithWhatsApp(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'code' => 'wa_outbound_arch',
            'name' => 'WA',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 0,
            'is_active' => true,
            'features' => ['whatsapp', 'conversations'],
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

        $account = WhatsAppAccount::query()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'waba_arch',
            'display_name' => 'Arch WA',
            'status' => 'connected',
            'metadata' => [],
        ]);

        WhatsAppPhoneNumber::query()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => 'pnid_manual',
            'display_phone_number' => '+966500000001',
            'verified_name' => 'Arch',
            'status' => 'connected',
        ]);

        return [$workspace, $user];
    }

    /**
     * @return array{0: Workspace, 1: WorkspaceMetaConnection, 2: User}
     */
    private function workspaceWithInstagram(string $instagramAccountId): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::INSTAGRAM_USER,
            'external_id' => $instagramAccountId,
            'display_name' => '@arch.test',
            'status' => 'connected',
            'scopes' => ['instagram_business_manage_messages'],
            'metadata' => [
                'channel' => 'instagram',
                'graph_api_version' => 'v20.0',
            ],
            'connected_by_user_id' => $user->id,
        ]);
        $connection->storeAccessToken('instagram-user-access-token');
        $connection->save();

        return [$workspace, $connection, $user];
    }
}
