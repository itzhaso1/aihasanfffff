<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation;

use App\Events\Realtime\EmployeeJoinedConversation;
use App\Events\Realtime\EmployeeLeftConversation;
use App\Events\Realtime\TypingActivity;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Conversation\ConversationPresenceService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ConversationPresenceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationPresenceService $presenceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        $this->presenceService = app(ConversationPresenceService::class);
    }

    public function test_multiple_employees_can_join_and_be_present_simultaneously(): void
    {
        Event::fake([EmployeeJoinedConversation::class]);

        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent1 = $this->workspaceAgent($workspace, 'حسن الحسينات');
        $agent2 = $this->workspaceAgent($workspace, 'براء');
        $agent3 = $this->workspaceAgent($workspace, 'خالد');

        // Agent 1 joins
        $this->actingAs($agent1);
        $this->withSession(['current_workspace_id' => $workspace->id]);
        $res1 = $this->postJson(route('workspace.conversations.presence.ping', $conversation), [
            'state' => 'viewing',
        ]);
        $res1->assertOk()
            ->assertJsonPath('data.present_count', 1)
            ->assertJsonPath('data.present_employees.0.name', 'حسن الحسينات');

        Event::assertDispatched(EmployeeJoinedConversation::class, function ($event) use ($conversation, $agent1) {
            return $event->conversationId === $conversation->id && $event->employee['id'] === $agent1->id;
        });

        // Agent 2 joins
        $this->actingAs($agent2);
        $res2 = $this->postJson(route('workspace.conversations.presence.ping', $conversation), [
            'state' => 'viewing',
        ]);
        $res2->assertOk()
            ->assertJsonPath('data.present_count', 2);

        // Agent 3 joins
        $this->actingAs($agent3);
        $res3 = $this->postJson(route('workspace.conversations.presence.ping', $conversation), [
            'state' => 'viewing',
        ]);
        $res3->assertOk()
            ->assertJsonPath('data.present_count', 3);

        $presentNames = collect($res3->json('data.present_employees'))->pluck('name')->all();
        $this->assertContains('حسن الحسينات', $presentNames);
        $this->assertContains('براء', $presentNames);
        $this->assertContains('خالد', $presentNames);
    }

    public function test_concurrent_typing_records_all_typing_users_and_generates_correct_summary(): void
    {
        Event::fake([TypingActivity::class]);

        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent1 = $this->workspaceAgent($workspace, 'حسن');
        $agent2 = $this->workspaceAgent($workspace, 'براء');

        // Agent 1 starts typing
        $this->actingAs($agent1);
        $this->withSession(['current_workspace_id' => $workspace->id]);
        $res1 = $this->postJson(route('workspace.conversations.typing', $conversation), [
            'is_typing' => true,
        ]);
        $res1->assertOk()
            ->assertJsonPath('typing_summary', 'حسن يكتب الآن...');

        Event::assertDispatched(TypingActivity::class, function ($event) use ($agent1) {
            return $event->userId === $agent1->id && $event->typingSummary === 'حسن يكتب الآن...';
        });

        // Agent 2 also starts typing concurrently
        $this->actingAs($agent2);
        $res2 = $this->postJson(route('workspace.conversations.typing', $conversation), [
            'is_typing' => true,
        ]);
        $res2->assertOk()
            ->assertJsonPath('typing_summary', 'حسن وبراء يكتبان الآن...');

        // Verify state has both typers
        $state = $this->presenceService->getConversationState($conversation);
        $this->assertCount(2, $state['typing_employees']);

        // Agent 1 stops typing
        $this->actingAs($agent1);
        $res3 = $this->postJson(route('workspace.conversations.typing', $conversation), [
            'is_typing' => false,
        ]);
        $res3->assertOk()
            ->assertJsonPath('typing_summary', 'براء يكتب الآن...');

        // Only Agent 2 remains
        $stateAfter = $this->presenceService->getConversationState($conversation);
        $this->assertCount(1, $stateAfter['typing_employees']);
        $this->assertSame('براء', $stateAfter['typing_employees'][0]['name']);
    }

    public function test_sending_message_preserves_sender_identity_and_retains_assigned_employee(): void
    {
        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent1 = $this->workspaceAgent($workspace, 'حسن الحسينات');
        $agent2 = $this->workspaceAgent($workspace, 'براء');

        // Assign conversation to Agent 1
        $conversation->update([
            'assigned_user_id' => $agent1->id,
            'assigned_user_name' => $agent1->name,
            'handoff_status' => 'active',
            'responder_type' => 'human',
            'responder_name' => $agent1->name,
        ]);

        // Agent 2 sends a message in the thread
        $this->actingAs($agent2);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $res = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'content' => 'مرحباً، أرد عليك نيابة عن زميلي',
            'direction' => 'outbound',
        ]);
        $res->assertOk();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $agent2->id,
            'direction' => 'outbound',
            'content' => 'مرحباً، أرد عليك نيابة عن زميلي',
        ]);

        $message = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->latest('id')->first();
        $this->assertNotNull($message);
        $this->assertSame($agent2->id, (int) $message->user_id);
        $this->assertSame('براء', $message->user?->name);

        // Conversation assignment must still belong to Agent 1!
        $conversation->refresh();
        $this->assertSame($agent1->id, (int) $conversation->assigned_user_id);
    }

    public function test_employee_leaves_conversation_broadcasts_leave_and_removes_from_presence(): void
    {
        Event::fake([EmployeeLeftConversation::class]);

        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent1 = $this->workspaceAgent($workspace, 'حسن');
        $agent2 = $this->workspaceAgent($workspace, 'براء');

        $this->presenceService->join($conversation, $agent1);
        $this->presenceService->join($conversation, $agent2);

        $this->actingAs($agent1);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $res = $this->postJson(route('workspace.conversations.presence.leave', $conversation));
        $res->assertOk()
            ->assertJsonPath('status', 'ok');

        Event::assertDispatched(EmployeeLeftConversation::class, function ($event) use ($conversation, $agent1) {
            return $event->conversationId === $conversation->id && $event->userId === $agent1->id;
        });

        $active = $this->presenceService->getPresentEmployees($conversation);
        $this->assertCount(1, $active);
        $this->assertSame($agent2->id, $active[0]['id']);
    }

    public function test_heartbeat_timeout_automatically_prunes_offline_employees(): void
    {
        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent1 = $this->workspaceAgent($workspace, 'حسن');
        $agent2 = $this->workspaceAgent($workspace, 'براء');

        $this->presenceService->join($conversation, $agent1);
        $this->presenceService->join($conversation, $agent2);

        // Simulate agent1 timed out 40 seconds ago (TTL is 35 seconds)
        $presenceMap = Cache::get("conversation:{$conversation->id}:presence_map");
        $this->assertIsArray($presenceMap);
        $presenceMap[(string) $agent1->id]['last_seen_at'] = now()->timestamp - 40;
        Cache::put("conversation:{$conversation->id}:presence_map", $presenceMap, 3600);

        // Fetch present employees
        $present = $this->presenceService->getPresentEmployees($conversation);

        // Only agent2 should remain
        $this->assertCount(1, $present);
        $this->assertSame($agent2->id, $present[0]['id']);
        $this->assertSame('براء', $present[0]['name']);
    }

    public function test_manager_and_mobile_api_view_same_canonical_presence_state(): void
    {
        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent = $this->workspaceAgent($workspace, 'حسن');

        $this->presenceService->join($conversation, $agent, 'viewing');

        // Web endpoint
        $this->actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);
        $webRes = $this->getJson(route('workspace.conversations.presence', $conversation));
        $webRes->assertOk();

        // Mobile API endpoint
        $mobileRes = $this->actingAs($owner, 'sanctum')
            ->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson("/api/mobile/v1/conversations/{$conversation->id}/presence");
        $mobileRes->assertOk();

        $this->assertSame(
            $webRes->json('data.present_employees.0.name'),
            $mobileRes->json('data.present_employees.0.name')
        );
        $this->assertSame(
            $webRes->json('data.present_count'),
            $mobileRes->json('data.present_count')
        );
    }

    public function test_cross_workspace_isolation_blocks_presence_access(): void
    {
        [$user1, $workspace1, $conversation1] = $this->seedConversation();
        [$user2, $workspace2, $conversation2] = $this->seedConversation();

        $this->actingAs($user2);
        $this->withSession(['current_workspace_id' => $workspace2->id]);

        // Attempting to ping presence on workspace 1 conversation
        $pingRes = $this->postJson(route('workspace.conversations.presence.ping', $conversation1), [
            'state' => 'viewing',
        ]);
        $this->assertTrue(in_array($pingRes->status(), [403, 404]));

        // Mobile attempt
        $mobileRes = $this->actingAs($user2, 'sanctum')
            ->withHeader('X-Workspace-Id', (string) $workspace2->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation1->id}/presence/ping", [
                'state' => 'viewing',
            ]);
        $this->assertTrue(in_array($mobileRes->status(), [403, 404]));
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Conversation}
     */
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
            'name' => 'عميل تجريبي',
            'phone' => '0500123456',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'external_id' => 'web:presence-test-'.uniqid(),
            'status' => 'open',
            'ai_enabled' => true,
            'responder_type' => 'ai',
            'responder_name' => 'مشعل AI',
        ]);

        return [$user, $workspace, $conversation];
    }

    private function workspaceAgent(Workspace $workspace, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }
}
