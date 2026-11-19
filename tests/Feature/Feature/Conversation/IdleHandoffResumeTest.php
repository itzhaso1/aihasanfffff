<?php

namespace Tests\Feature\Feature\Conversation;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Conversation\IdleHandoffResumeService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IdleHandoffResumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_idle_active_handoff_conversation_is_auto_resumed(): void
    {
        [$owner, , $conversation] = $this->seedConversation();

        $conversation->update([
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Active->value,
            'assigned_user_id' => $owner->id,
            'handoff_assigned_at' => now()->subMinutes(10),
            'employee_last_replied_at' => now()->subMinutes(10),
        ]);

        $resumed = app(IdleHandoffResumeService::class)->resumeIdleConversations();

        $this->assertSame(1, $resumed);

        $conversation->refresh();
        $this->assertTrue((bool) $conversation->ai_enabled);
        $this->assertSame(ConversationHandoffStatus::None, $conversation->handoff_status);
        $this->assertTrue($conversation->isAiReplying());

        // Internal note was written
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'internal_note',
            'message_type' => 'system',
        ]);
    }

    public function test_queued_handoff_conversation_with_no_reply_is_auto_resumed(): void
    {
        [, , $conversation] = $this->seedConversation();

        $conversation->update([
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Queued->value,
            'handoff_assigned_at' => now()->subMinutes(10),
            'employee_last_replied_at' => null, // Never replied
        ]);

        $resumed = app(IdleHandoffResumeService::class)->resumeIdleConversations();

        $this->assertSame(1, $resumed);

        $conversation->refresh();
        $this->assertTrue((bool) $conversation->ai_enabled);
        $this->assertSame(ConversationHandoffStatus::None, $conversation->handoff_status);
    }

    public function test_conversation_with_recent_employee_reply_is_not_auto_resumed(): void
    {
        [, , $conversation] = $this->seedConversation();

        $conversation->update([
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Active->value,
            'handoff_assigned_at' => now()->subMinutes(10),
            'employee_last_replied_at' => now()->subMinutes(2), // Replied 2 minutes ago
        ]);

        $resumed = app(IdleHandoffResumeService::class)->resumeIdleConversations();

        $this->assertSame(0, $resumed);

        $conversation->refresh();
        $this->assertFalse((bool) $conversation->ai_enabled);
        $this->assertSame(ConversationHandoffStatus::Active, $conversation->handoff_status);
    }

    public function test_conversation_with_active_employee_presence_is_not_auto_resumed(): void
    {
        [, , $conversation] = $this->seedConversation();

        $conversation->update([
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Active->value,
            'handoff_assigned_at' => now()->subMinutes(10),
            'employee_last_replied_at' => now()->subMinutes(10),
        ]);

        // Simulate an active presence heartbeat (last seen 10 seconds ago)
        Cache::put("conversation:{$conversation->id}:presence_map", [
            '99' => [
                'id' => 99,
                'name' => 'Ahmed',
                'last_seen_at' => now()->timestamp - 10, // 10 seconds ago — still active
            ],
        ], now()->addHours(1));

        $resumed = app(IdleHandoffResumeService::class)->resumeIdleConversations();

        $this->assertSame(0, $resumed);

        $conversation->refresh();
        $this->assertFalse((bool) $conversation->ai_enabled);

        Cache::forget("conversation:{$conversation->id}:presence_map");
    }

    public function test_closed_conversation_is_not_auto_resumed(): void
    {
        [, , $conversation] = $this->seedConversation();

        $conversation->update([
            'status' => 'closed',
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Active->value,
            'employee_last_replied_at' => now()->subMinutes(10),
        ]);

        $resumed = app(IdleHandoffResumeService::class)->resumeIdleConversations();

        $this->assertSame(0, $resumed);
    }

    public function test_conversation_with_none_handoff_status_is_not_auto_resumed(): void
    {
        [, , $conversation] = $this->seedConversation();

        $conversation->update([
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::None->value,
            'employee_last_replied_at' => now()->subMinutes(10),
        ]);

        $resumed = app(IdleHandoffResumeService::class)->resumeIdleConversations();

        $this->assertSame(0, $resumed);
    }

    public function test_fresh_assignment_within_threshold_is_not_auto_resumed(): void
    {
        [, , $conversation] = $this->seedConversation();

        $conversation->update([
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Assigned->value,
            // Assignment happened 2 minutes ago — within the 5-minute threshold
            'handoff_assigned_at' => now()->subMinutes(2),
            'employee_last_replied_at' => null,
        ]);

        $resumed = app(IdleHandoffResumeService::class)->resumeIdleConversations();

        $this->assertSame(0, $resumed);
    }

    public function test_outbound_employee_message_updates_employee_last_replied_at(): void
    {
        [$owner, $workspace, $conversation] = $this->seedConversation();

        $conversation->update([
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Active->value,
        ]);

        $this->assertNull($conversation->fresh()->employee_last_replied_at);

        $this->actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'content' => 'مرحباً',
            'direction' => 'outbound',
            'message_type' => 'text',
        ])->assertOk();

        $this->assertNotNull($conversation->fresh()->employee_last_replied_at);
    }

    public function test_multiple_idle_conversations_are_all_auto_resumed(): void
    {
        [$owner, $workspace, $conversation1] = $this->seedConversation();
        $customer2 = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Customer Two',
            'phone' => '0500000002',
        ]);
        $conversation2 = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer2->id,
            'channel' => 'web',
            'external_id' => 'web:idle-test-2',
            'status' => 'open',
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Active->value,
            'handoff_assigned_at' => now()->subMinutes(10),
            'employee_last_replied_at' => now()->subMinutes(10),
        ]);

        $conversation1->update([
            'ai_enabled' => false,
            'handoff_status' => ConversationHandoffStatus::Active->value,
            'handoff_assigned_at' => now()->subMinutes(10),
            'employee_last_replied_at' => now()->subMinutes(10),
        ]);

        $resumed = app(IdleHandoffResumeService::class)->resumeIdleConversations();

        $this->assertSame(2, $resumed);
    }

    // ─── Seed helpers ────────────────────────────────────────────────────────

    private function seedConversation(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Customer',
            'phone' => '0500000001',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'external_id' => 'web:idle-handoff-test',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        return [$user, $workspace, $conversation];
    }
}
