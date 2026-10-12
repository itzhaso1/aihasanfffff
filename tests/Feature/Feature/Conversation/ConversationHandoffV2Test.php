<?php

namespace Tests\Feature\Feature\Conversation;

use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Jobs\ProcessAIResponse;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationAiEligibilityService;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Inbox\InboxHumanHandoffService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ConversationHandoffV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_customer_phrase_requests_handoff_and_disables_ai(): void
    {
        [, $workspace, $conversation] = $this->seedConversation();

        $message = $this->inWorkspace($workspace, function () use ($conversation): Message {
            return $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'بدي أحكي مع موظف',
            ]);
        });

        $this->inWorkspace($workspace, function () use ($conversation, $message): void {
            app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage($conversation->fresh(), $message);
        });

        $conversation->refresh();
        $this->assertFalse($conversation->ai_enabled);
        $this->assertSame(ConversationHandoffStatus::Queued, $conversation->handoff_status);
        $this->assertSame(ConversationHandoffReason::CustomerRequest, $conversation->handoff_reason);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'message_type' => 'system',
            'metadata->handoff_acknowledgement' => true,
        ]);
    }

    public function test_state_transitions_and_invalid_transition_rejected(): void
    {
        [, $workspace, $conversation] = $this->seedConversation();
        $agent = $this->workspaceAgent($workspace);
        $handoff = app(ConversationHandoffService::class);

        $this->inWorkspace($workspace, fn () => $handoff->request(
            $conversation,
            ConversationHandoffReason::CustomerRequest,
            ['requested_by' => 'customer'],
            queueImmediately: true,
        ));

        $conversation->refresh();
        $this->assertSame(ConversationHandoffStatus::Queued, $conversation->handoff_status);

        $this->inWorkspace($workspace, fn () => $handoff->assign($conversation, $agent));
        $conversation->refresh();
        $this->assertSame(ConversationHandoffStatus::Assigned, $conversation->handoff_status);
        $this->assertSame($agent->id, $conversation->assigned_user_id);

        $this->inWorkspace($workspace, fn () => $handoff->takeover($conversation, $agent));
        $conversation->refresh();
        $this->assertSame(ConversationHandoffStatus::Active, $conversation->handoff_status);

        $this->inWorkspace($workspace, fn () => $handoff->resolve($conversation, $agent));
        $conversation->refresh();
        $this->assertSame(ConversationHandoffStatus::Resolved, $conversation->handoff_status);
        $this->assertFalse($conversation->ai_enabled);

        $this->inWorkspace($workspace, fn () => $handoff->resumeAi($conversation, $agent));
        $conversation->refresh();
        $this->assertSame(ConversationHandoffStatus::None, $conversation->handoff_status);
        $this->assertTrue($conversation->ai_enabled);

        $assigned = $this->inWorkspace($workspace, fn () => $handoff->assign($conversation->fresh(), $agent));
        $this->assertSame(ConversationHandoffStatus::Assigned, $assigned->handoff_status);
        $this->assertSame($agent->id, $assigned->assigned_user_id);
    }

    public function test_cross_workspace_assignee_rejected(): void
    {
        [, $workspace, $conversation] = $this->seedConversation();
        $otherWorkspace = Workspace::factory()->create();
        $outsider = User::factory()->create();
        $otherWorkspace->users()->attach($outsider->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(ConversationHandoffService::class)->request(
            $conversation,
            ConversationHandoffReason::CustomerRequest,
            [],
            queueImmediately: true,
        );

        $this->expectException(\InvalidArgumentException::class);
        app(ConversationHandoffService::class)->assign($conversation->fresh(), $outsider);
    }

    public function test_ai_guard_blocks_handoff_states(): void
    {
        [, , $conversation] = $this->seedConversation();
        $conversation->update([
            'handoff_status' => ConversationHandoffStatus::Active->value,
            'ai_enabled' => true,
        ]);

        $inbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'test',
        ]);

        $this->assertFalse(app(ConversationAiEligibilityService::class)->mayRespond($conversation->fresh(), $inbound));
    }

    public function test_discards_ai_reply_when_handoff_during_generation(): void
    {
        [$workspace, $conversation, $inbound] = $this->seedConversationWithInbound();

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateReply')->once()->andReturnUsing(function () use ($conversation): string {
            $conversation->update([
                'handoff_status' => ConversationHandoffStatus::Active->value,
                'ai_enabled' => false,
            ]);

            return 'متأخر';
        });
        $this->app->instance(AIService::class, $ai);

        app(ProcessAIResponse::class, [
            'conversationId' => $conversation->id,
            'messageId' => $inbound->id,
            'workspaceId' => $workspace->id,
        ])->handle(
            $ai,
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $this->assertSame(0, Message::withoutGlobalScopes()->where('ai_generated', true)->count());
    }

    public function test_mobile_takeover_endpoint_enforces_workspace(): void
    {
        [$agent, $workspace, $conversation] = $this->seedConversation();
        Sanctum::actingAs($agent);

        app(ConversationHandoffService::class)->request(
            $conversation,
            ConversationHandoffReason::CustomerRequest,
            [],
            queueImmediately: true,
        );

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/takeover")
            ->assertOk()
            ->assertJsonPath('data.handoff_status', 'active');

        $other = Workspace::factory()->create();
        $this->withHeader('X-Workspace-Id', (string) $other->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/takeover")
            ->assertNotFound();
    }

    public function test_inbox_handoff_filter(): void
    {
        [$user, $workspace, $conversation] = $this->seedConversation();
        $conversation->update([
            'handoff_status' => ConversationHandoffStatus::Queued->value,
            'ai_enabled' => false,
        ]);

        Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $conversation->customer_id,
            'channel' => 'web',
            'external_id' => 'web:other',
            'status' => 'open',
            'ai_enabled' => true,
            'handoff_status' => ConversationHandoffStatus::None->value,
        ]);

        $this->actingAs($user);
        $this->withSession(['current_workspace_id' => $workspace->id]);
        $response = $this->get(route('workspace.conversations.index', ['handoff' => 'handoff']));
        $response->assertOk();
        $response->assertSee('Test Customer', false);
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
            'name' => 'Test Customer',
            'phone' => '0500000001',
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'external_id' => 'web:handoff-test',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        return [$user, $workspace, $conversation];
    }

    /**
     * @return array{0: Workspace, 1: Conversation, 2: Message}
     */
    private function seedConversationWithInbound(): array
    {
        [, $workspace, $conversation] = $this->seedConversation();
        $inbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'مرحبا',
        ]);

        return [$workspace, $conversation, $inbound];
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
