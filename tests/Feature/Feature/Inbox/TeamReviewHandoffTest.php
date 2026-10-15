<?php

namespace Tests\Feature\Feature\Inbox;

use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Inbox\InboxHumanHandoffDetector;
use App\Services\Inbox\InboxHumanHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamReviewHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recorded_quantity_question_does_not_open_team_review(): void
    {
        [$user, $workspace, $conversation] = $this->conversation();
        $message = $this->inbound($conversation, 'كم الكمية المتوفرة من الدفتر؟');

        $this->assertNull(app(InboxHumanHandoffDetector::class)->staffDecisionReason($message->content));
        $this->assertFalse(app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage($conversation, $message));

        $fresh = $conversation->fresh();
        $this->assertSame(ConversationHandoffStatus::None, $fresh->handoffStatusEnum());
        $this->assertSame(0, $this->acknowledgements($conversation));
        $this->assertTrue($user->exists && $workspace->exists);
    }

    public function test_a_staff_decision_opens_team_review_and_shows_the_badge(): void
    {
        [$user, $workspace, $conversation] = $this->conversation();
        $message = $this->inbound($conversation, 'أبي أسترجع المبلغ');

        $this->assertTrue(app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage($conversation, $message));

        $fresh = $conversation->fresh();
        $this->assertTrue($fresh->handoffStatusEnum()->blocksAi());
        $this->assertSame(ConversationHandoffReason::RefundRequest, $fresh->handoff_reason);
        $this->assertSame(1, $this->acknowledgements($conversation));

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('تحتاج مراجعة من الفريق', false)
            ->assertSee('إنهاء المراجعة', false);
    }

    public function test_repeating_the_request_does_not_create_another_review(): void
    {
        [, , $conversation] = $this->conversation();
        $message = $this->inbound($conversation, 'أبي أسترجع المبلغ');
        $service = app(InboxHumanHandoffService::class);

        $this->assertTrue($service->maybeHandoffFromInboundMessage($conversation, $message));
        $this->assertFalse($service->maybeHandoffFromInboundMessage($conversation->fresh(), $message));

        $again = $this->inbound($conversation, 'أبي أسترجع المبلغ مرة ثانية');
        $this->assertFalse($service->maybeHandoffFromInboundMessage($conversation->fresh(), $again));
        $this->assertSame(1, $this->acknowledgements($conversation));
    }

    public function test_review_stays_in_its_workspace_until_staff_resolves_it(): void
    {
        [$user, $workspace, $conversation] = $this->conversation();
        $otherWorkspace = Workspace::factory()->create(['type' => 'company']);
        $other = Conversation::factory()->create([
            'workspace_id' => $otherWorkspace->id,
            'customer_id' => Customer::factory()->create(['workspace_id' => $otherWorkspace->id])->id,
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $this->assertTrue(app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage(
            $conversation,
            $this->inbound($conversation, 'عندي شكوى على الطلب'),
        ));

        $this->assertSame(ConversationHandoffStatus::None, $other->fresh()->handoffStatusEnum());

        $conversation->update($conversation->attributesForInboundCustomerMessage([
            'last_message_at' => now(),
        ]));
        $conversation->refresh();
        $this->assertTrue($conversation->handoffStatusEnum()->blocksAi());

        app(ConversationHandoffService::class)->resolve($conversation, $user);
        $this->assertSame(ConversationHandoffStatus::Resolved, $conversation->fresh()->handoffStatusEnum());
        $this->assertFalse($conversation->fresh()->handoffStatusEnum()->blocksAi());
        $this->assertSame($workspace->id, $conversation->workspace_id);
    }

    public function test_failed_review_registration_does_not_claim_a_handoff(): void
    {
        [, , $conversation] = $this->conversation();
        $conversation->update(['status' => 'closed']);
        $message = $this->inbound($conversation, 'أبي أسترجع المبلغ');

        $this->assertFalse(app(InboxHumanHandoffService::class)->maybeHandoffFromInboundMessage($conversation, $message));
        $this->assertSame(ConversationHandoffStatus::None, $conversation->fresh()->handoffStatusEnum());
        $this->assertSame(0, $this->acknowledgements($conversation));
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Conversation}
     */
    private function conversation(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $customer = Customer::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل المراجعة',
        ]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        return [$user, $workspace, $conversation];
    }

    private function inbound(Conversation $conversation, string $content): Message
    {
        return Message::withoutGlobalScopes()->create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => $content,
            'ai_generated' => false,
        ]);
    }

    private function acknowledgements(Conversation $conversation): int
    {
        return Message::withoutGlobalScopes()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('metadata->handoff_acknowledgement', true)
            ->count();
    }
}
