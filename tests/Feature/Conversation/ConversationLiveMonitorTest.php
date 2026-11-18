<?php

namespace Tests\Feature\Conversation;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Conversation\ConversationHandoffService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationLiveMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_default_responder_is_ai_active(): void
    {
        [$user, $workspace, $conversation] = $this->seedConversation();

        $responder = $conversation->currentResponder();

        $this->assertSame('ai_active', $responder['status']);
        $this->assertSame('مشعل AI', $responder['name']);
        $this->assertSame('AI يرد الآن', $responder['label']);
    }

    public function test_employee_takeover_switches_responder_to_human_active_and_records_audit_log(): void
    {
        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent = $this->workspaceAgent($workspace);

        $this->actingAs($agent);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->post(route('workspace.conversations.handoff.takeover', $conversation));
        $response->assertRedirect();

        $conversation->refresh();
        $this->assertSame('human', $conversation->responder_type);
        $this->assertSame($agent->name, $conversation->responder_name);
        $this->assertSame($agent->id, $conversation->assigned_user_id);
        $this->assertSame(ConversationHandoffStatus::Active, $conversation->handoff_status);
        $this->assertFalse((bool) $conversation->ai_enabled);

        $responder = $conversation->currentResponder();
        $this->assertSame('human_active', $responder['status']);
        $this->assertSame($agent->name, $responder['name']);

        $this->assertDatabaseHas('conversation_responder_logs', [
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'actor_id' => $agent->id,
            'action' => 'takeover',
            'new_responder_type' => 'human',
            'new_responder_name' => $agent->name,
        ]);
    }

    public function test_employee_outbound_reply_auto_assigns_and_switches_responder(): void
    {
        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent = $this->workspaceAgent($workspace);

        $this->actingAs($agent);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->postJson(route('workspace.conversations.messages.store', $conversation), [
            'conversation_id' => $conversation->id,
            'content' => 'مرحباً، أنا أتابع طلبك الآن وسأخدمك في ثواني.',
            'direction' => 'outbound',
        ]);

        $response->assertOk();
        $response->assertJsonPath('conversation.responder.status', 'human_active');
        $response->assertJsonPath('conversation.responder.name', $agent->name);

        $conversation->refresh();
        $this->assertSame($agent->id, $conversation->assigned_user_id);
        $this->assertSame('human', $conversation->responder_type);
        $this->assertSame($agent->name, $conversation->responder_name);
        $this->assertSame(ConversationHandoffStatus::Active, $conversation->handoff_status);
        $this->assertFalse((bool) $conversation->ai_enabled);

        $this->assertDatabaseHas('conversation_responder_logs', [
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'action' => 'human_message_sent',
            'new_responder_type' => 'human',
            'new_responder_name' => $agent->name,
        ]);
    }

    public function test_resuming_ai_switches_responder_to_ai_active_and_records_audit_log(): void
    {
        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent = $this->workspaceAgent($workspace);

        $handoff = app(ConversationHandoffService::class);
        $handoff->takeover($conversation, $agent);

        $conversation->refresh();
        $this->assertSame('human_active', $conversation->currentResponder()['status']);

        // Owner has permission to resume AI
        $this->actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->post(route('workspace.conversations.handoff.resume-ai', $conversation));
        $response->assertRedirect();

        $conversation->refresh();
        $this->assertTrue((bool) $conversation->ai_enabled);
        $this->assertNull($conversation->assigned_user_id);
        $this->assertSame(ConversationHandoffStatus::None, $conversation->handoff_status);
        $this->assertSame('ai', $conversation->responder_type);
        $this->assertSame('مشعل AI', $conversation->responder_name);

        $this->assertDatabaseHas('conversation_responder_logs', [
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'action' => 'returned_to_ai',
            'new_responder_type' => 'ai',
            'new_responder_name' => 'مشعل AI',
        ]);
    }

    public function test_collision_prevention_ai_cannot_generate_when_assigned_to_human(): void
    {
        [$owner, $workspace, $conversation] = $this->seedConversation();
        $agent = $this->workspaceAgent($workspace);

        $message = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'مرحبا، بدي استفسر عن طلب',
        ]);

        // Assign to human
        $conversation->update([
            'assigned_user_id' => $agent->id,
            'handoff_status' => ConversationHandoffStatus::Active->value,
            'ai_enabled' => false,
            'responder_type' => 'human',
            'responder_name' => $agent->name,
        ]);

        $eligibility = app(AiReplyEligibility::class);
        $this->assertFalse($eligibility->mayGenerate($conversation, $message));
        $this->assertFalse($conversation->isAiReplying());
    }

    public function test_live_monitor_page_and_json_endpoint(): void
    {
        [$user, $workspace, $conversation] = $this->seedConversation();
        $agent = $this->workspaceAgent($workspace);

        // Make conversation assigned to agent
        app(ConversationHandoffService::class)->takeover($conversation, $agent);

        $this->actingAs($user);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        // 1. Web Page view
        $pageResponse = $this->get(route('workspace.conversations.monitor'));
        $pageResponse->assertOk();
        $pageResponse->assertSee('المراقبة المباشرة للمحادثات');
        $pageResponse->assertSee($agent->name);

        // 2. Real-time JSON data endpoint
        $jsonResponse = $this->getJson(route('workspace.conversations.monitor.data'));
        $jsonResponse->assertOk();
        $jsonResponse->assertJsonStructure([
            'counts' => [
                'total_open',
                'ai_count',
                'human_count',
                'human_needed_count',
                'waiting_count',
                'closed_count',
            ],
            'active_employees',
            'conversations',
        ]);

        $jsonResponse->assertJsonPath('counts.human_count', 1);
        $this->assertNotEmpty($jsonResponse->json('active_employees'));
        $this->assertSame($agent->name, $jsonResponse->json('active_employees.0.name'));
    }

    public function test_workspace_isolation_cannot_view_or_takeover_other_workspace_conversation(): void
    {
        [$user1, $workspace1, $conversation1] = $this->seedConversation();
        [$user2, $workspace2, $conversation2] = $this->seedConversation();

        $this->actingAs($user2);
        $this->withSession(['current_workspace_id' => $workspace2->id]);

        // Attempt takeover on workspace 1 conversation
        $takeoverResponse = $this->post(route('workspace.conversations.handoff.takeover', $conversation1));
        $this->assertTrue(in_array($takeoverResponse->status(), [403, 404]));

        // Attempt reading audit logs of workspace 1 conversation
        $logsResponse = $this->getJson(route('workspace.conversations.responder-logs', $conversation1));
        $this->assertTrue(in_array($logsResponse->status(), [403, 404]));
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
            'external_id' => 'web:mon-test-'.uniqid(),
            'status' => 'open',
            'ai_enabled' => true,
            'responder_type' => 'ai',
            'responder_name' => 'مشعل AI',
        ]);

        return [$user, $workspace, $conversation];
    }

    private function workspaceAgent(Workspace $workspace): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }
}
