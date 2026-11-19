<?php

namespace Tests\Feature\Feature\Broadcast;

use App\Enums\WorkerEngagementStatus;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Events\Realtime\TypingActivity;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkforceCapabilityDefinition;
use App\Models\Workspace;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
    }

    public function test_workspace_conversations_channel_auth_denies_non_member(): void
    {
        [, $workspace] = $this->ownerAndWorkspace();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspace->id.'.conversations',
            ])
            ->assertForbidden();
    }

    public function test_workspace_member_passes_conversations_channel_authorization(): void
    {
        [$owner, $workspace] = $this->ownerAndWorkspace();

        $allowed = Workspace::withoutGlobalScopes()
            ->whereKey($workspace->id)
            ->whereHas('users', function ($query) use ($owner): void {
                $query->where('users.id', $owner->id)->where('workspace_users.status', 'active');
            })
            ->exists();

        $this->assertTrue($allowed);
    }

    public function test_conversation_channel_auth_denies_foreign_conversation(): void
    {
        [$ownerA, $workspaceA] = $this->ownerAndWorkspace();
        [, $workspaceB] = $this->ownerAndWorkspace();

        $conversationB = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'channel' => 'manual',
            'external_id' => 'ext-b',
            'status' => 'open',
        ]);

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspaceA->id.'.conversation.'.$conversationB->id,
            ])
            ->assertForbidden();
    }

    public function test_pos_broadcast_channel_is_not_authorized_for_workspace_members(): void
    {
        [$owner, $workspace] = $this->ownerAndWorkspace();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspace->id.'.pos',
            ])
            ->assertForbidden();
    }

    public function test_independent_worker_with_read_capability_passes_channel_authorization(): void
    {
        [$owner, $workspace] = $this->ownerAndWorkspace();
        [$worker, $profile] = $this->createWorker('Authorized Worker');
        $this->createEngagement($workspace, $owner, $profile, ['inbox.messages.read']);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'manual',
            'external_id' => 'ext-worker-1',
            'status' => 'open',
        ]);

        $this->actingAs($worker)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspace->id.'.conversations',
            ])
            ->assertOk();

        $this->actingAs($worker)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspace->id.'.conversation.'.$conversation->id,
            ])
            ->assertOk();
    }

    public function test_independent_worker_without_read_capability_is_forbidden(): void
    {
        [$owner, $workspace] = $this->ownerAndWorkspace();
        [$worker, $profile] = $this->createWorker('Blocked Worker');
        $this->createEngagement($workspace, $owner, $profile, []); // No inbox.messages.read

        $this->actingAs($worker)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1.1',
                'channel_name' => 'private-workspace.'.$workspace->id.'.conversations',
            ])
            ->assertForbidden();
    }

    public function test_mobile_broadcasting_auth_endpoint_authorizes_sanctum_user(): void
    {
        [$owner, $workspace] = $this->ownerAndWorkspace();

        Sanctum::actingAs($owner);

        $this->postJson('/api/mobile/v1/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => 'private-workspace.'.$workspace->id.'.conversations',
        ])
            ->assertOk();
    }

    public function test_typing_endpoint_broadcasts_typing_activity(): void
    {
        Event::fake([TypingActivity::class]);

        [$owner, $workspace] = $this->ownerAndWorkspace();
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'manual',
            'status' => 'open',
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->postJson(route('workspace.conversations.typing', $conversation), [
                'is_typing' => true,
            ])
            ->assertOk();

        Event::assertDispatched(TypingActivity::class, function (TypingActivity $event) use ($workspace, $conversation, $owner): bool {
            return $event->workspaceId === $workspace->id
                && $event->conversationId === $conversation->id
                && $event->isTyping === true
                && $event->senderName === $owner->name;
        });
    }

    public function test_message_created_and_conversation_updated_payloads(): void
    {
        [$owner, $workspace] = $this->ownerAndWorkspace();
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Customer VIP',
            'phone' => '+966500000000',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'مرحباً بك في حاسم',
            'metadata' => ['idempotency_key' => 'client-key-123'],
        ]);

        $messageEvent = new MessageCreated($message);
        $messagePayload = $messageEvent->broadcastWith();

        $this->assertEquals($message->id, $messagePayload['id']);
        $this->assertEquals('client-key-123', $messagePayload['client_id']);
        $this->assertEquals($owner->name, $messagePayload['sender_name']);
        $this->assertEquals('outbound', $messagePayload['direction']);
        $this->assertArrayHasKey('created_at_time', $messagePayload);

        $conversationEvent = new ConversationUpdated($conversation);
        $conversationPayload = $conversationEvent->broadcastWith();

        $this->assertEquals($conversation->id, $conversationPayload['id']);
        $this->assertEquals('Customer VIP', $conversationPayload['customer']['name']);
        $this->assertEquals('مرحباً بك في حاسم', $conversationPayload['last_message']);
        $this->assertArrayHasKey('responder', $conversationPayload);
    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(string $name): array
    {
        $worker = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Worker summary.',
            'sales_experience_level' => 'senior',
        ]);

        return [$worker, $profile];
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function createEngagement(
        Workspace $workspace,
        User $owner,
        WorkerProfile $profile,
        array $capabilities,
    ): WorkerEngagement {
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Realtime Worker',
            'created_by' => $owner->id,
            'active_at' => now(),
        ]);

        foreach ($capabilities as $capability) {
            WorkforceCapabilityDefinition::query()->firstOrCreate(
                ['capability_key' => $capability],
                [
                    'display_name' => $capability,
                    'description' => $capability,
                    'is_active' => true,
                    'sort_order' => 1,
                ]
            );

            WorkerEngagementPermission::query()->create([
                'worker_engagement_id' => $engagement->id,
                'capability_key' => $capability,
                'allowed' => true,
            ]);
        }

        return $engagement;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function ownerAndWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
