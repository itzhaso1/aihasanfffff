<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation;

use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Events\Realtime\TypingActivity;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class RealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
    }

    public function test_message_creation_broadcasts_on_both_workspace_and_conversation_channels(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);

        [$owner, $workspace, $conversation] = $this->createConversation();

        $this->actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'content' => 'مرحباً، هذه رسالة تجريبية فورية',
            'client_id' => 'temp-test-12345',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message.client_id', 'temp-test-12345');

        Event::assertDispatched(MessageCreated::class, function (MessageCreated $event) use ($conversation, $workspace) {
            $channels = $event->broadcastOn();
            $channelNames = array_map(fn ($ch) => $ch->name, $channels);

            $hasWorkspaceChannel = in_array('private-workspace.'.$workspace->id.'.conversations', $channelNames, true);
            $hasConversationChannel = in_array('private-workspace.'.$workspace->id.'.conversation.'.$conversation->id, $channelNames, true);

            $payload = $event->broadcastWith();

            return $hasWorkspaceChannel
                && $hasConversationChannel
                && $payload['conversation_id'] === $conversation->id
                && $payload['client_id'] === 'temp-test-12345'
                && $event->broadcastAs() === 'message.created';
        });

        Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) use ($conversation, $workspace) {
            $channels = $event->broadcastOn();
            $channelNames = array_map(fn ($ch) => $ch->name, $channels);

            $hasWorkspaceChannel = in_array('private-workspace.'.$workspace->id.'.conversations', $channelNames, true);
            $hasConversationChannel = in_array('private-workspace.'.$workspace->id.'.conversation.'.$conversation->id, $channelNames, true);

            return $hasWorkspaceChannel
                && $hasConversationChannel
                && $event->broadcastAs() === 'conversation.updated';
        });
    }

    public function test_conversation_status_change_broadcasts_update(): void
    {
        Event::fake([ConversationUpdated::class]);

        [$owner, $workspace, $conversation] = $this->createConversation();

        $this->actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->put(route('workspace.conversations.update', $conversation), [
            'status' => 'archived',
            'ai_enabled' => '0',
        ]);

        $response->assertRedirect();
        $this->assertEquals('archived', $conversation->fresh()->status);

        Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) use ($conversation) {
            return $event->conversation->id === $conversation->id
                && $event->conversation->status === 'archived';
        });
    }

    public function test_typing_activity_broadcasts_immediately_with_should_broadcast_now(): void
    {
        Event::fake([TypingActivity::class]);

        [$owner, $workspace, $conversation] = $this->createConversation();

        $this->actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->postJson(route('workspace.conversations.typing', $conversation), [
            'is_typing' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('is_typing', true);

        Event::assertDispatched(TypingActivity::class, function (TypingActivity $event) use ($conversation, $owner) {
            $channels = $event->broadcastOn();
            $channelNames = array_map(fn ($ch) => $ch->name, $channels);

            $payload = $event->broadcastWith();

            return in_array('private-workspace.'.$conversation->workspace_id.'.conversation.'.$conversation->id, $channelNames, true)
                && $event->isTyping === true
                && $event->userId === $owner->id
                && $payload['is_typing'] === true;
        });
    }

    public function test_channel_authorization_allows_workspace_member_and_denies_outsider(): void
    {
        [$owner, $workspace, $conversation] = $this->createConversation();
        $outsider = User::factory()->create();

        // 1. Authorized member on workspace conversations channel
        $this->actingAs($owner, 'web');
        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspace.'.$workspace->id.'.conversations',
            'socket_id' => '123.456',
        ]);
        $response->assertOk();

        // 2. Unauthorized outsider on workspace conversations channel
        $this->flushSession();
        $this->actingAs($outsider);
        $responseOutsider = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspace.'.$workspace->id.'.conversations',
            'socket_id' => '123.456',
        ]);
        $responseOutsider->assertForbidden();

        // 3. Authorized member on specific conversation channel
        $this->flushSession();
        $this->actingAs($owner);
        $responseConv = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspace.'.$workspace->id.'.conversation.'.$conversation->id,
            'socket_id' => '123.456',
        ]);
        $responseConv->assertOk();

        // 4. Unauthorized outsider on specific conversation channel
        $this->flushSession();
        $this->actingAs($outsider);
        $responseConvOutsider = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspace.'.$workspace->id.'.conversation.'.$conversation->id,
            'socket_id' => '123.456',
        ]);
        $responseConvOutsider->assertForbidden();

        // 5. Cross-workspace IDOR protection: valid member of workspace A cannot access conversation of workspace B
        $otherUser = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_user_id' => $otherUser->id]);
        $otherWorkspace->users()->attach($otherUser->id, ['membership_role' => 'admin', 'status' => 'active', 'joined_at' => now()]);

        $this->flushSession();
        $this->actingAs($otherUser);
        $responseCross = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspace.'.$workspace->id.'.conversation.'.$conversation->id,
            'socket_id' => '123.456',
        ]);
        $responseCross->assertForbidden();
    }

    public function test_poll_updates_returns_realtime_conversation_state(): void
    {
        [$owner, $workspace, $conversation] = $this->createConversation();

        Message::create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'رسالة اختبار للـ poll updates',
            'message_type' => 'text',
        ]);

        $this->actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->getJson(route('workspace.conversations.updates.poll'));

        $response->assertOk()
            ->assertJsonStructure([
                'conversations' => [
                    '*' => [
                        'id',
                        'customer_name',
                        'channel',
                        'last_message',
                        'unread_count',
                        'status',
                    ],
                ],
            ]);

        $found = collect($response->json('conversations'))->firstWhere('id', $conversation->id);
        $this->assertNotNull($found);
        $this->assertEquals('رسالة اختبار للـ poll updates', $found['last_message']);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Conversation}
     */
    private function createConversation(): array
    {
        $user = User::factory()->create(['name' => 'مالك مساحة العمل']);
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل تجريبي',
            'phone' => '+966512345678',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'external_id' => 'ext_'.uniqid(),
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        return [$user, $workspace, $conversation];
    }
}
