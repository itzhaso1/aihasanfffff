<?php

namespace Tests\Feature\Feature\Meta;

use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Feature\Meta\Concerns\InteractsWithMetaWebhook;
use Tests\TestCase;

class InstagramWebhookInboundTest extends TestCase
{
    use InteractsWithMetaWebhook;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessAIResponse::class]);
        config()->set('meta.app_secret', 'meta_instagram_secret');
        config()->set('meta.webhook_verify_token', 'meta-instagram-verify');
    }

    public function test_instagram_webhook_verification_challenge_is_accepted(): void
    {
        $this->get('/meta-webhook?hub.mode=subscribe&hub.verify_token=meta-instagram-verify&hub.challenge=ig-challenge-ok')
            ->assertOk()
            ->assertSee('ig-challenge-ok');
    }

    public function test_inbound_text_dm_creates_customer_conversation_and_message(): void
    {
        [$workspace] = $this->workspaceWithInstagramAccount('17841400000000001');
        $payload = $this->instagramTextPayload(
            igAccountId: '17841400000000001',
            senderId: '1348953895323378',
            messageId: 'aWdfZAG1faXRlbToxOklHTWVzc2FnZA1',
            text: 'مرحبا من إنستغرام',
        );

        $this->postMetaWebhook($payload, 'meta_instagram_secret')->assertAccepted();

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());

        $customer = Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('instagram:1348953895323378', $customer->client_reference);
        $this->assertSame('1348953895323378', $customer->metadata['instagram_user_id'] ?? null);

        $conversation = Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('instagram', $conversation->channel);
        $this->assertSame('instagram:1348953895323378', $conversation->external_id);
        $this->assertSame('open', $conversation->status);
        $this->assertTrue($conversation->ai_enabled);
        $this->assertSame($customer->id, $conversation->customer_id);
        $this->assertSame('instagram', $conversation->metadata['channel_source'] ?? null);
        $this->assertSame('1348953895323378', $conversation->metadata['instagram_user_id'] ?? null);
        $this->assertSame('17841400000000001', $conversation->metadata['instagram_account_id'] ?? null);

        $message = Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('text', $message->message_type);
        $this->assertSame('مرحبا من إنستغرام', $message->content);
        $this->assertSame('aWdfZAG1faXRlbToxOklHTWVzc2FnZA1', $message->external_message_id);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame($customer->id, $message->customer_id);
        $this->assertSame('instagram', $message->metadata['channel'] ?? null);
        $this->assertArrayNotHasKey('access_token', $message->metadata ?? []);
    }

    public function test_changes_messages_payload_is_ingested(): void
    {
        [$workspace] = $this->workspaceWithInstagramAccount('17841400000000001');
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17841400000000001',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'sender' => ['id' => 'igsid-changes'],
                        'recipient' => ['id' => '17841400000000001'],
                        'timestamp' => 1700000000,
                        'message' => [
                            'mid' => 'mid.changes.1',
                            'text' => 'from changes field',
                        ],
                    ],
                ]],
            ]],
        ];

        $this->postMetaWebhook($payload, 'meta_instagram_secret')->assertAccepted();

        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('external_message_id', 'mid.changes.1')
            ->count());
    }

    public function test_duplicate_webhook_does_not_create_a_second_message(): void
    {
        [$workspace] = $this->workspaceWithInstagramAccount('17841400000000001');
        $payload = $this->instagramTextPayload('17841400000000001', 'igsid-dup', 'mid.dup.1', 'hello once');

        $this->postMetaWebhook($payload, 'meta_instagram_secret')->assertAccepted();
        $this->postMetaWebhook($payload, 'meta_instagram_secret')->assertAccepted();

        $this->assertSame(1, WebhookEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'instagram')
            ->where('external_event_id', 'mid.dup.1')
            ->count());
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('external_message_id', 'mid.dup.1')
            ->count());
        $this->assertSame(1, Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_second_dm_from_same_sender_reuses_conversation(): void
    {
        [$workspace] = $this->workspaceWithInstagramAccount('17841400000000001');

        $this->postMetaWebhook(
            $this->instagramTextPayload('17841400000000001', 'igsid-same', 'mid.first', 'first'),
            'meta_instagram_secret',
        )->assertAccepted();

        $this->postMetaWebhook(
            $this->instagramTextPayload('17841400000000001', 'igsid-same', 'mid.second', 'second'),
            'meta_instagram_secret',
        )->assertAccepted();

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(2, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());

        $conversation = Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(['first', 'second'], Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->pluck('content')
            ->all());
    }

    public function test_instagram_dm_is_isolated_to_the_connected_workspace(): void
    {
        [$workspaceA] = $this->workspaceWithInstagramAccount('ig-account-a');
        [$workspaceB] = $this->workspaceWithInstagramAccount('ig-account-b');

        $this->postMetaWebhook(
            $this->instagramTextPayload('ig-account-a', 'igsid-tenant', 'mid.tenant.a', 'only A'),
            'meta_instagram_secret',
        )->assertAccepted();

        $this->assertSame(1, Message::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
        $this->assertSame(0, Conversation::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
        $this->assertSame(0, Customer::withoutGlobalScopes()->where('workspace_id', $workspaceB->id)->count());
    }

    public function test_unknown_instagram_account_does_not_create_records(): void
    {
        [$workspace] = $this->workspaceWithInstagramAccount('ig-account-known');

        $this->postMetaWebhook(
            $this->instagramTextPayload('ig-account-unknown', 'igsid-x', 'mid.unknown', 'hello'),
            'meta_instagram_secret',
        )->assertAccepted();

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_non_message_instagram_events_are_ignored(): void
    {
        [$workspace] = $this->workspaceWithInstagramAccount('17841400000000001');
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17841400000000001',
                'changes' => [[
                    'field' => 'comments',
                    'value' => [
                        'from' => ['id' => 'igsid-commenter'],
                        'text' => 'this is a comment not a dm',
                    ],
                ]],
                'messaging' => [[
                    'sender' => ['id' => 'igsid-reader'],
                    'recipient' => ['id' => '17841400000000001'],
                    'read' => ['mid' => 'mid.read.1'],
                ]],
            ]],
        ];

        $this->postMetaWebhook($payload, 'meta_instagram_secret')->assertAccepted();

        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
    }

    public function test_echo_messages_are_ignored(): void
    {
        [$workspace] = $this->workspaceWithInstagramAccount('17841400000000001');
        $payload = $this->instagramTextPayload('17841400000000001', '17841400000000001', 'mid.echo.1', 'we sent this');
        $payload['entry'][0]['messaging'][0]['recipient']['id'] = 'igsid-customer';
        $payload['entry'][0]['messaging'][0]['message']['is_echo'] = true;

        $this->postMetaWebhook($payload, 'meta_instagram_secret')->assertAccepted();

        $this->assertSame(0, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_webhook_logs_do_not_contain_secrets_or_message_bodies(): void
    {
        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs): void {
            $logs[] = json_encode([$event->message, $event->context], JSON_UNESCAPED_UNICODE) ?: '';
        });

        [$workspace] = $this->workspaceWithInstagramAccount('17841400000000001');
        $payload = $this->instagramTextPayload('17841400000000001', 'igsid-secret-log', 'mid.secret.1', 'Sensitive customer message body');
        $payload['entry'][0]['messaging'][0]['message']['access_token'] = 'super-secret-instagram-token';

        $this->postMetaWebhook($payload, 'meta_instagram_secret')->assertAccepted();

        $blob = implode("\n", $logs);
        foreach ([
            'super-secret-instagram-token',
            'Sensitive customer message body',
            'instagram-user-access-token',
            'meta_instagram_secret',
        ] as $value) {
            $this->assertStringNotContainsString($value, $blob);
        }

        $this->assertGreaterThan(0, Message::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_native_instagram_conversation_appears_in_inbox_channel_filter(): void
    {
        [$workspace, , $owner] = $this->workspaceWithInstagramAccount('17841400000000001');
        $this->postMetaWebhook(
            $this->instagramTextPayload('17841400000000001', 'igsid-inbox', 'mid.inbox.1', 'Hello from Instagram'),
            'meta_instagram_secret',
        )->assertAccepted();

        $conversation = Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', [
                'channel' => 'instagram',
                'conversation' => $conversation->id,
            ]))
            ->assertOk()
            ->assertSee('Hello from Instagram')
            ->assertSee('Instagram');
    }

    public function test_inbox_reply_uses_instagram_graph_without_token_in_logs(): void
    {
        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs): void {
            $logs[] = json_encode([$event->message, $event->context], JSON_UNESCAPED_UNICODE) ?: '';
        });

        Http::fake([
            'graph.instagram.com/*' => Http::response([
                'recipient_id' => 'igsid-reply',
                'message_id' => 'mid.outbound.1',
            ], 200),
        ]);

        [$workspace, , $owner] = $this->workspaceWithInstagramAccount('17841400000000001');
        $this->postMetaWebhook(
            $this->instagramTextPayload('17841400000000001', 'igsid-reply', 'mid.in.1', 'ping'),
            'meta_instagram_secret',
        )->assertAccepted();

        $conversation = Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.conversations.messages.store', $conversation), [
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => 'reply from inbox',
            ])
            ->assertRedirect();

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'graph.instagram.com/v20.0/me/messages')
                && ($data['recipient']['id'] ?? null) === 'igsid-reply'
                && ($data['message']['text'] ?? null) === 'reply from inbox';
        });

        $outbound = Message::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('direction', 'outbound')
            ->firstOrFail();
        $this->assertSame('mid.outbound.1', $outbound->external_message_id);
        $this->assertStringNotContainsString('instagram-user-access-token', implode("\n", $logs));
    }

    /**
     * @return array{0: Workspace, 1: WorkspaceMetaConnection, 2: User}
     */
    private function workspaceWithInstagramAccount(string $instagramAccountId): array
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
            'display_name' => '@hasim.test',
            'status' => 'connected',
            'scopes' => ['instagram_business_basic', 'instagram_business_manage_messages'],
            'metadata' => [
                'channel' => 'instagram',
                'graph_api_version' => 'v20.0',
                'graph_host' => 'graph.instagram.com',
            ],
            'connected_by_user_id' => $user->id,
        ]);
        $connection->storeAccessToken('instagram-user-access-token');
        $connection->save();

        return [$workspace, $connection, $user];
    }

    /**
     * @return array<string, mixed>
     */
    private function instagramTextPayload(string $igAccountId, string $senderId, string $messageId, string $text): array
    {
        return [
            'object' => 'instagram',
            'entry' => [[
                'id' => $igAccountId,
                'time' => 1700000000,
                'messaging' => [[
                    'sender' => ['id' => $senderId],
                    'recipient' => ['id' => $igAccountId],
                    'timestamp' => 1700000000123,
                    'message' => [
                        'mid' => $messageId,
                        'text' => $text,
                    ],
                ]],
            ]],
        ];
    }
}
