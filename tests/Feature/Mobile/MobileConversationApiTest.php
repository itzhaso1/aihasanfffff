<?php

namespace Tests\Feature\Mobile;

use App\Models\ApiIdempotencyKey;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileConversationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_list_send_read_and_idempotent_message(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل تجريبي',
            'phone' => '+966500000099',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'مرحبا',
        ]);

        $list = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/conversations');
        $this->assertTrue($list->isSuccessful(), (string) $list->getContent());
        $list->assertJsonPath('success', true);

        $send = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Idempotency-Key', 'msg-key-1')
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
                'content' => 'رد من حاسم',
                'idempotency_key' => 'msg-key-1',
            ])
            ->assertCreated();

        $messageId = $send->json('data.id');
        $this->assertSame('msg-key-1', $send->json('data.client_id'));
        $this->assertSame('msg-key-1', $send->json('data.idempotency_key'));

        $dup = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Idempotency-Key', 'msg-key-1')
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
                'content' => 'رد من حاسم',
                'idempotency_key' => 'msg-key-1',
            ])
            ->assertCreated();

        $this->assertSame($messageId, $dup->json('data.id'));
        $this->assertSame('msg-key-1', $dup->json('data.client_id'));
        $this->assertSame(1, Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/read")
            ->assertOk();

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/unread')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/mobile/v1/home')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_active_member_can_send_text_and_other_workspace_is_rejected(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace, $ownerToken] = $this->authMember('owner-send@example.com');
        $conversation = $this->makeConversation($workspace);
        $member = User::factory()->create([
            'email' => 'member-send@example.com',
            'password' => 'password',
        ]);
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $memberLogin = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $member->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();
        $memberToken = $memberLogin->json('data.token');

        $this->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
            'content' => 'مرحبا',
            'message_type' => 'text',
        ])->assertUnauthorized();

        $sent = $this->withToken($memberToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Idempotency-Key', 'marhaba-1')
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
                'content' => 'مرحبا',
                'message_type' => 'text',
                'idempotency_key' => 'marhaba-1',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.content', 'مرحبا')
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.message_type', 'text')
            ->assertJsonPath('data.conversation_id', $conversation->id);

        $this->assertNotNull($sent->json('data.id'));
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('workspace_id', $workspace->id)
            ->where('direction', 'outbound')
            ->where('content', 'مرحبا')
            ->count());

        [$other, $otherWorkspace, $otherToken] = $this->authMember('other-send@example.com');
        $this->withToken($otherToken)
            ->withHeader('X-Workspace-Id', (string) $otherWorkspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
                'content' => 'مرحبا',
                'message_type' => 'text',
            ])
            ->assertNotFound();

        $this->assertNotNull($owner);
        $this->assertNotNull($other);
        $this->assertNotSame($workspace->id, $otherWorkspace->id);
    }

    public function test_lost_response_retry_replays_the_same_outbound_message(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('lost@example.com');
        $conversation = $this->makeConversation($workspace);

        $first = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Idempotency-Key', 'lost-key-1')
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
                'content' => 'رسالة ضاعت استجابتها',
                'idempotency_key' => 'lost-key-1',
            ])
            ->assertCreated();

        $messageId = $first->json('data.id');
        $this->assertNotNull($messageId);

        ApiIdempotencyKey::query()->where('key', 'lost-key-1')->delete();

        $retry = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Idempotency-Key', 'lost-key-1')
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/messages", [
                'content' => 'رسالة ضاعت استجابتها',
                'idempotency_key' => 'lost-key-1',
            ])
            ->assertCreated();

        $this->assertSame($messageId, $retry->json('data.id'));
        $this->assertSame('lost-key-1', $retry->json('data.client_id'));
        $this->assertSame(1, Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());

        $page = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson("/api/mobile/v1/conversations/{$conversation->id}/messages")
            ->assertOk();

        $ids = collect($page->json('data'))->pluck('id')->all();
        $this->assertContains($messageId, $ids);
        $this->assertSame('lost-key-1', collect($page->json('data'))->firstWhere('id', $messageId)['client_id']);
    }

    public function test_tenant_isolation_blocks_foreign_conversation(): void
    {
        $this->seed(FoundationSeeder::class);
        [$userA, $workspaceA, $tokenA] = $this->authMember('a@example.com');
        [$userB, $workspaceB] = $this->makeMember('b@example.com');

        $conversationB = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'channel' => 'web',
            'status' => 'open',
        ]);

        $response = $this->withToken($tokenA)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->getJson("/api/mobile/v1/conversations/{$conversationB->id}");

        $this->assertContains($response->status(), [403, 404]);
        $this->assertNotEquals(200, $response->status());
    }

    public function test_archive_mute_and_filters_use_user_state(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('archive-mute@example.com');
        $conversation = $this->makeConversation($workspace);
        $headers = ['X-Workspace-Id' => (string) $workspace->id];

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/archive", ['archived' => true])
            ->assertOk();
        $this->assertSame('archived', $conversation->fresh()->status);

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/mobile/v1/conversations?filter=all')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $archived = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/mobile/v1/conversations?filter=archived')
            ->assertOk();
        $archived->assertJsonPath('data.0.id', $conversation->id);
        $this->assertTrue((bool) $archived->json('data.0.archived'));

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/archive", ['archived' => false])
            ->assertOk();
        $this->assertSame('open', $conversation->fresh()->status);

        $this->withToken($token)
            ->withHeaders($headers)
            ->getJson('/api/mobile/v1/conversations?filter=all')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id);

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/mute", ['muted' => true])
            ->assertOk();

        $muted = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson("/api/mobile/v1/conversations/{$conversation->id}")
            ->assertOk();
        $this->assertTrue((bool) $muted->json('data.muted'));

        $this->withToken($token)
            ->withHeaders($headers)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/mute", ['muted' => false])
            ->assertOk();

        $unmuted = $this->withToken($token)
            ->withHeaders($headers)
            ->getJson("/api/mobile/v1/conversations/{$conversation->id}")
            ->assertOk();
        $this->assertFalse((bool) $unmuted->json('data.muted'));
    }

    public function test_archive_on_foreign_workspace_conversation_is_forbidden(): void
    {
        $this->seed(FoundationSeeder::class);
        [$userA, $workspaceA, $tokenA] = $this->authMember('archive-a@example.com');
        [$userB, $workspaceB] = $this->makeMember('archive-b@example.com');
        $conversationB = $this->makeConversation($workspaceB);

        $response = $this->withToken($tokenA)
            ->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->postJson("/api/mobile/v1/conversations/{$conversationB->id}/archive", ['archived' => true]);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertNotEquals(200, $response->status());
    }

    public function test_device_push_token_register_and_revoke(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('push@example.com');

        $created = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/devices', [
                'token' => 'fcm-test-token-123',
                'provider' => 'fcm',
                'platform' => 'android',
                'device_name' => 'Pixel',
            ])
            ->assertCreated();

        $deviceId = $created->json('data.id');

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->deleteJson("/api/mobile/v1/devices/{$deviceId}")
            ->assertOk();
    }

    private function makeConversation(Workspace $workspace): Conversation
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل تجريبي',
            'phone' => '+966500000088',
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email = 'conv@example.com'): array
    {
        [$user, $workspace] = $this->makeMember($email);
        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        return [$user, $workspace, $login->json('data.token')];
    }

    /**
     * @return array{0:User,1:Workspace}
     */
    private function makeMember(string $email): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => 'password',
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
