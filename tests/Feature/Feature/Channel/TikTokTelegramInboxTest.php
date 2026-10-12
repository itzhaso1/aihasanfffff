<?php

namespace Tests\Feature\Feature\Channel;

use App\Jobs\ProcessIncomingTelegramMessage;
use App\Jobs\ProcessIncomingTikTokMessage;
use App\Jobs\SendChannelOutboundMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceTelegramConnection;
use App\Models\WorkspaceTikTokConnection;
use App\Services\Conversation\ConversationService;
use App\Services\TikTok\TikTokWebhookService;
use App\Support\Meta\MetaOAuthState;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TikTokTelegramInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiktok_webhook_rejects_invalid_signature(): void
    {
        config(['tiktok.client_secret' => 'test-secret']);

        $this->postJson('/tiktok-webhook', ['event' => 'im_receive_msg'])
            ->assertForbidden();
    }

    public function test_tiktok_inbound_text_queues_job_and_deduplicates(): void
    {
        [$workspace] = $this->workspacePair();
        WorkspaceTikTokConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_open_id' => 'biz-open-1',
            'display_name' => 'Biz',
            'status' => WorkspaceTikTokConnection::STATUS_CONNECTED,
        ]);

        config(['tiktok.client_secret' => 'test-secret']);

        $content = json_encode([
            'unique_identifier' => 'user-abc',
            'from_user' => ['role' => 'personal_account', 'id' => 'user-abc'],
            'to_user' => ['role' => 'business_account', 'id' => 'biz-open-1'],
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-1',
            'type' => 'text',
            'text' => ['body' => 'Hello TikTok'],
            'timestamp' => 1701090879815,
        ], JSON_THROW_ON_ERROR);

        $payload = [
            'event' => 'im_receive_msg',
            'user_openid' => 'biz-open-1',
            'content' => $content,
        ];

        Queue::fake();

        $this->signedTikTokPost($payload)->assertOk();
        $this->signedTikTokPost($payload)->assertOk();

        Queue::assertPushed(ProcessIncomingTikTokMessage::class, 1);
    }

    public function test_tiktok_inbound_persists_customer_conversation_and_ai_dispatch(): void
    {
        [$workspace] = $this->workspacePair();
        WorkspaceTikTokConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'business_open_id' => 'biz-open-1',
            'status' => WorkspaceTikTokConnection::STATUS_CONNECTED,
        ]);

        app(TikTokWebhookService::class)->storeIncomingMessage($workspace->id, [
            'business_open_id' => 'biz-open-1',
            'sender_unique_id' => 'user-abc',
            'conversation_id' => 'conv-1',
            'message_id' => 'msg-persist-1',
            'text' => 'Hi',
            'message_type' => 'text',
            'metadata' => [],
            'attachments' => [],
        ]);

        $this->assertDatabaseHas('customers', ['client_reference' => 'tiktok:user-abc']);
        $this->assertDatabaseHas('conversations', ['external_id' => 'tiktok:user-abc', 'channel' => 'tiktok']);
        $this->assertSame(1, Message::withoutGlobalScopes()->count());
    }

    public function test_tiktok_oauth_state_purpose_is_enforced(): void
    {
        $issued = app(MetaOAuthState::class)->issue(1, 1, MetaOAuthState::PURPOSE_TIKTOK);

        $this->expectException(\RuntimeException::class);
        app(MetaOAuthState::class)->verify($issued['state'], MetaOAuthState::PURPOSE_INSTAGRAM);
    }

    public function test_telegram_webhook_requires_valid_secret(): void
    {
        $connection = $this->telegramConnection();

        $this->postJson('/telegram-webhook/'.$connection->public_webhook_key, ['update_id' => 1])
            ->assertForbidden();
    }

    public function test_telegram_inbound_queues_job_once_for_duplicate_update(): void
    {
        $connection = $this->telegramConnection();

        $update = [
            'update_id' => 99,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'text' => 'Salam',
            ],
        ];

        Queue::fake();

        $this->postJson('/telegram-webhook/'.$connection->public_webhook_key, $update, [
            'X-Telegram-Bot-Api-Secret-Token' => $connection->webhookSecret(),
        ])->assertOk();

        $this->postJson('/telegram-webhook/'.$connection->public_webhook_key, $update, [
            'X-Telegram-Bot-Api-Secret-Token' => $connection->webhookSecret(),
        ])->assertOk();

        Queue::assertPushed(ProcessIncomingTelegramMessage::class, 1);
    }

    public function test_manual_telegram_reply_queues_outbound_job(): void
    {
        [$workspace, $user] = $this->workspacePair();
        $this->telegramConnection($workspace);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'TG User',
            'phone' => 'telegram:42',
            'client_reference' => 'telegram:42',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'telegram',
            'external_id' => 'telegram:777',
            'status' => 'open',
            'metadata' => [
                'channel_source' => 'telegram',
                'telegram_chat_id' => '777',
            ],
        ]);

        Queue::fake();

        app(WorkspaceContext::class)->runForId($workspace->id, function () use ($conversation, $user): void {
            app(ConversationService::class)->addMessage($conversation, [
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => 'Reply from agent',
            ], $user);
        });

        Queue::assertPushed(SendChannelOutboundMessage::class);
    }

    public function test_workspace_b_cannot_resolve_workspace_a_tiktok_connection(): void
    {
        [$workspaceA] = $this->workspacePair();
        [$workspaceB] = $this->workspacePair();

        WorkspaceTikTokConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'business_open_id' => 'shared-biz',
            'status' => WorkspaceTikTokConnection::STATUS_CONNECTED,
        ]);

        config(['tiktok.client_secret' => 'test-secret']);
        Queue::fake();

        $content = json_encode([
            'unique_identifier' => 'user-x',
            'from_user' => ['role' => 'personal_account', 'id' => 'user-x'],
            'conversation_id' => 'c1',
            'message_id' => 'm1',
            'type' => 'text',
            'text' => ['body' => 'x'],
        ], JSON_THROW_ON_ERROR);

        $this->signedTikTokPost([
            'event' => 'im_receive_msg',
            'user_openid' => 'shared-biz',
            'content' => $content,
        ])->assertOk();

        Queue::assertPushed(ProcessIncomingTikTokMessage::class, 1);

        WorkspaceTikTokConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'business_open_id' => 'shared-biz',
            'status' => WorkspaceTikTokConnection::STATUS_CONNECTED,
        ]);

        Queue::fake();
        $this->signedTikTokPost([
            'event' => 'im_receive_msg',
            'user_openid' => 'shared-biz',
            'content' => json_encode([
                'unique_identifier' => 'user-y',
                'from_user' => ['role' => 'personal_account', 'id' => 'user-y'],
                'conversation_id' => 'c2',
                'message_id' => 'm2',
                'type' => 'text',
                'text' => ['body' => 'y'],
            ], JSON_THROW_ON_ERROR),
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function workspacePair(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$workspace, $user];
    }

    private function telegramConnection(?Workspace $workspace = null): WorkspaceTelegramConnection
    {
        $workspace ??= Workspace::factory()->create();

        $connection = new WorkspaceTelegramConnection([
            'workspace_id' => $workspace->id,
            'bot_user_id' => '999001',
            'bot_username' => 'testbot',
            'public_webhook_key' => 'test-webhook-key-123',
            'status' => WorkspaceTelegramConnection::STATUS_CONNECTED,
        ]);
        $connection->storeWebhookSecret('secret-token-abc');
        $connection->storeBotToken('123456:TEST');
        $connection->save();

        return $connection->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedTikTokPost(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'test-secret');

        return $this->call(
            'POST',
            '/tiktok-webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_TikTok-Signature' => 't='.$timestamp.',s='.$signature,
            ],
            $body,
        );
    }
}
