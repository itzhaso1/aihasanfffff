<?php

namespace Tests\Feature;

use App\Jobs\ProcessConversationBulkMessageJob;
use App\Models\Conversation;
use App\Models\ConversationBulkSend;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inbox\ConversationBulkMessageService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationBulkMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_send_queues_one_outbound_message_per_conversation(): void
    {
        $this->seed(FoundationSeeder::class);
        Queue::fake();
        [$user, $workspace, $token] = $this->authMember('bulk-send@example.com');
        $conversations = [
            $this->makeConversation($workspace, 1),
            $this->makeConversation($workspace, 2),
            $this->makeConversation($workspace, 3),
        ];
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Workspace-Id' => (string) $workspace->id,
            'Idempotency-Key' => 'bulk-key-1',
        ];

        $response = $this->withHeaders($headers)->postJson(
            '/api/mobile/v1/conversations/bulk-messages',
            [
                'conversation_ids' => array_map(
                    static fn (Conversation $conversation): int => $conversation->id,
                    $conversations,
                ),
                'content' => 'رسالة جماعية تجريبية',
            ],
        );

        $response->assertStatus(202)
            ->assertJsonPath('data.queued_count', 3)
            ->assertJsonPath('data.skipped_count', 0);
        Queue::assertPushed(ProcessConversationBulkMessageJob::class);

        $bulkId = (string) $response->json('data.bulk_send_id');
        app(ConversationBulkMessageService::class)->process(
            $bulkId,
            (int) $workspace->id,
            'رسالة جماعية تجريبية',
        );

        $this->assertSame(
            3,
            Message::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('direction', 'outbound')
                ->where('content', 'رسالة جماعية تجريبية')
                ->count(),
        );
        $this->assertNotNull($user);
    }

    public function test_cross_workspace_conversation_is_not_accessible(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('bulk-isolation@example.com');
        [, $otherWorkspace] = $this->makeMember('bulk-other@example.com');
        $foreign = $this->makeConversation($otherWorkspace, 99);

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/conversations/bulk-messages', [
                'conversation_ids' => [$foreign->id],
                'content' => 'لا ترسل',
            ])
            ->assertNotFound();

        $this->assertSame(0, Message::withoutGlobalScopes()
            ->where('conversation_id', $foreign->id)
            ->where('direction', 'outbound')
            ->count());
        $this->assertNotNull($user);
    }

    public function test_select_all_applies_the_current_channel_filter(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('bulk-filter@example.com');
        $this->makeConversation($workspace, 1, 'whatsapp');
        $this->makeConversation($workspace, 2, 'whatsapp');
        $this->makeConversation($workspace, 3, 'telegram');

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/conversations/bulk-messages', [
                'select_all' => true,
                'filters' => ['filter' => 'all', 'channel' => 'whatsapp'],
                'content' => 'واتساب فقط',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.queued_count', 2)
            ->assertJsonPath('data.skipped_count', 0);
    }

    public function test_duplicate_idempotency_key_does_not_create_two_bulk_sends(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('bulk-idempotency@example.com');
        $conversation = $this->makeConversation($workspace, 1);
        $payload = [
            'conversation_ids' => [$conversation->id],
            'content' => 'مرة واحدة',
            'idempotency_key' => 'bulk-repeat-1',
        ];

        $first = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Idempotency-Key', 'bulk-repeat-1')
            ->postJson('/api/mobile/v1/conversations/bulk-messages', $payload)
            ->assertStatus(202);
        $second = $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->withHeader('Idempotency-Key', 'bulk-repeat-1')
            ->postJson('/api/mobile/v1/conversations/bulk-messages', $payload)
            ->assertStatus(202);

        $this->assertSame($first->json('data.bulk_send_id'), $second->json('data.bulk_send_id'));
        $this->assertSame(1, ConversationBulkSend::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->count());
    }

    public function test_handoff_conversation_is_skipped_with_a_reason(): void
    {
        $this->seed(FoundationSeeder::class);
        [$user, $workspace, $token] = $this->authMember('bulk-handoff@example.com');
        $conversation = $this->makeConversation($workspace, 1, 'whatsapp', 'active');

        $this->withToken($token)
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson('/api/mobile/v1/conversations/bulk-messages', [
                'conversation_ids' => [$conversation->id],
                'content' => 'تخطي التحويل',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.queued_count', 0)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped_preview.0.reason', 'human_handoff');
    }

    private function makeConversation(
        Workspace $workspace,
        int $externalId,
        string $channel = 'whatsapp',
        string $handoffStatus = 'none',
    ): Conversation {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل '.$externalId,
            'phone' => '+966500000'.str_pad((string) $externalId, 3, '0', STR_PAD_LEFT),
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'external_id' => 'external-'.$externalId,
            'status' => 'open',
            'handoff_status' => $handoffStatus,
            'last_message_at' => now(),
        ]);
    }

    /**
     * @return array{0:User,1:Workspace,2:string}
     */
    private function authMember(string $email): array
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
