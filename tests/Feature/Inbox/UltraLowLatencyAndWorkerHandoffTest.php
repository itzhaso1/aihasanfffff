<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Enums\ConversationHandoffStatus;
use App\Enums\WorkerEngagementStatus;
use App\Events\Realtime\ConversationUpdated;
use App\Events\Realtime\MessageCreated;
use App\Jobs\ProcessAIResponse;
use App\Models\ChannelOutboundDelivery;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\AI\AiOutputValidator;
use App\Services\AI\AIService;
use App\Services\AI\Inbox\AiReplyEligibility;
use App\Services\Channel\Outbound\ChannelOutboundService;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Inbox\InboxRealtimePublisher;
use App\Services\WhatsApp\WhatsAppOutboundService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AI\Inbox\CreatesHasimInboxFixtures;
use Tests\TestCase;

final class UltraLowLatencyAndWorkerHandoffTest extends TestCase
{
    use CreatesHasimInboxFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        $this->seed(WorkerCapabilitySeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'inbox.realtime.broadcast_connection' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
    }

    public function test_independent_worker_can_takeover_from_ai_and_transfer_back_on_mobile_without_auto_reply_to_old_messages(): void
    {
        Event::fake([ConversationUpdated::class]);
        Queue::fake([ProcessAIResponse::class]);

        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('سارة المستقلة');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->createOpenConversation($workspace, 'عميل واتساب', aiEnabled: true);
        $oldInbound = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'استفسار قديم قبل التولي',
        ]);

        Sanctum::actingAs($worker);

        $takeover = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/takeover", [
                'expected_handoff_version' => 1,
            ]);

        $takeover->assertOk()
            ->assertJsonPath('data.handoff_status', 'active')
            ->assertJsonPath('data.ai_enabled', false)
            ->assertJsonPath('data.ai_replying', false)
            ->assertJsonPath('data.assigned_user_id', $worker->id)
            ->assertJsonPath('data.assigned_worker_engagement_id', $engagement->id)
            ->assertJsonPath('data.handoff_version', 2)
            ->assertJsonPath('data.responder.status', 'human_active');

        $conversation->refresh();
        $this->assertSame(ConversationHandoffStatus::Active, $conversation->handoffStatusEnum());
        $this->assertFalse((bool) $conversation->ai_enabled);
        $this->assertSame($worker->id, $conversation->assigned_user_id);
        $this->assertSame($engagement->id, $conversation->assigned_worker_engagement_id);
        $this->assertSame(2, (int) $conversation->handoff_version);

        $this->assertDatabaseHas('conversation_responder_logs', [
            'conversation_id' => $conversation->id,
            'action' => 'takeover',
            'actor_id' => $worker->id,
            'new_responder_type' => 'human',
        ]);

        $resume = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/resume-ai", [
                'expected_handoff_version' => 2,
            ]);

        $resume->assertOk()
            ->assertJsonPath('data.handoff_status', 'none')
            ->assertJsonPath('data.ai_enabled', true)
            ->assertJsonPath('data.ai_replying', true)
            ->assertJsonPath('data.assigned_user_id', null)
            ->assertJsonPath('data.assigned_worker_engagement_id', null)
            ->assertJsonPath('data.handoff_version', 3)
            ->assertJsonPath('data.responder.status', 'ai_active');

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('messages', ['id' => $oldInbound->id]);
        Event::assertDispatched(ConversationUpdated::class, 2);

        $conversation->refresh();
        $this->assertTrue(app(AiReplyEligibility::class)->mayGenerate(
            $conversation,
            Message::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'رسالة عميل جديدة بعد التحويل للذكاء الاصطناعي',
            ]),
        ));
    }

    public function test_independent_worker_can_takeover_and_transfer_to_ai_via_web_ajax_without_reload(): void
    {
        Event::fake([ConversationUpdated::class]);

        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('خالد الدعم');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->createOpenConversation($workspace, 'عميل الويب', aiEnabled: true);

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [$workspace, $conversation->id]))
            ->assertOk()
            ->assertSee('تولي الرد', false)
            ->assertDontSee(route('workspace.conversations.handoff.takeover', $conversation), false);

        $takeoverRes = $this->actingAs($worker)
            ->postJson(route('worker.inbox.handoff.takeover', [$workspace, $conversation->id]), [
                'expected_handoff_version' => 1,
            ]);

        $takeoverRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation.handoff_status', 'active')
            ->assertJsonPath('conversation.assigned_user_id', $worker->id)
            ->assertJsonPath('conversation.assigned_worker_engagement_id', $engagement->id)
            ->assertJsonPath('conversation.handoff_version', 2);

        $resumeRes = $this->actingAs($worker)
            ->postJson(route('worker.inbox.handoff.resume-ai', [$workspace, $conversation->id]), [
                'expected_handoff_version' => 2,
            ]);

        $resumeRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('conversation.handoff_status', 'none')
            ->assertJsonPath('conversation.ai_enabled', true)
            ->assertJsonPath('conversation.handoff_version', 3);

        $this->actingAs($worker)
            ->getJson(route('worker.inbox.updates.poll', $workspace))
            ->assertOk()
            ->assertJsonPath('conversations.0.id', $conversation->id)
            ->assertJsonPath('conversations.0.handoff_version', 3);

        $this->actingAs($worker)
            ->getJson(route('worker.inbox.responder-logs', [$workspace, $conversation->id]))
            ->assertOk()
            ->assertJsonPath('conversation_id', $conversation->id);
    }

    public function test_read_only_worker_and_cross_workspace_worker_are_strictly_blocked(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('شركة أ');
        [$ownerB, $workspaceB] = $this->createCompany('شركة ب');
        [$readOnlyWorker, $profile] = $this->createWorker('قارئ فقط');
        $this->createEngagement($workspaceA, $ownerA, $profile, [
            'inbox.messages.read',
        ]);

        $convA = $this->createOpenConversation($workspaceA, 'عميل أ', aiEnabled: true);
        $convB = $this->createOpenConversation($workspaceB, 'عميل ب', aiEnabled: true);
        $archivedA = $this->createOpenConversation($workspaceA, 'مؤرشفة', aiEnabled: true, status: 'archived');

        Sanctum::actingAs($readOnlyWorker);

        $this->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->postJson("/api/mobile/v1/conversations/{$convA->id}/takeover")
            ->assertForbidden();

        $this->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->postJson("/api/mobile/v1/conversations/{$convA->id}/resume-ai")
            ->assertForbidden();

        $this->actingAs($readOnlyWorker)
            ->postJson(route('worker.inbox.handoff.takeover', [$workspaceA, $convA->id]))
            ->assertForbidden();

        $this->actingAs($readOnlyWorker)
            ->get(route('worker.inbox.show', [$workspaceA, $convA->id]))
            ->assertOk()
            ->assertDontSee('data-inbox-takeover-btn', false)
            ->assertDontSee('data-inbox-resume-ai-btn', false);

        Sanctum::actingAs($readOnlyWorker);
        $this->withHeader('X-Workspace-Id', (string) $workspaceB->id)
            ->postJson("/api/mobile/v1/conversations/{$convB->id}/takeover")
            ->assertNotFound();

        $this->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->postJson("/api/mobile/v1/conversations/{$convB->id}/takeover")
            ->assertNotFound();

        [$authorizedWorker, $authProfile] = $this->createWorker('موظف مصرح للأرشفة');
        $this->createEngagement($workspaceA, $ownerA, $authProfile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        Sanctum::actingAs($authorizedWorker);
        $this->withHeader('X-Workspace-Id', (string) $workspaceA->id)
            ->postJson("/api/mobile/v1/conversations/{$archivedA->id}/takeover")
            ->assertNotFound();
    }

    public function test_simultaneous_worker_takeover_conflicts_and_supervisor_override_preserved(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$workerA, $profileA] = $this->createWorker('موظف أول');
        [$workerB, $profileB] = $this->createWorker('موظف ثاني');
        $engagementA = $this->createEngagement($workspace, $owner, $profileA, ['inbox.messages.read', 'inbox.messages.send']);
        $this->createEngagement($workspace, $owner, $profileB, ['inbox.messages.read', 'inbox.messages.send']);
        $conversation = $this->createOpenConversation($workspace, 'عميل السباق', aiEnabled: true);

        Sanctum::actingAs($workerA);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/takeover", [
                'expected_handoff_version' => 1,
            ])
            ->assertOk();

        // Worker B sends stale version OR tries stealing Worker A's live chat
        Sanctum::actingAs($workerB);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/takeover", [
                'expected_handoff_version' => 1,
            ])
            ->assertStatus(409);

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/takeover")
            ->assertStatus(409);

        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/resume-ai")
            ->assertStatus(409);

        $conversation->refresh();
        $this->assertSame($workerA->id, $conversation->assigned_user_id);
        $this->assertSame($engagementA->id, $conversation->assigned_worker_engagement_id);

        // Company supervisor (owner) can override cleanly without stale version mismatch
        Sanctum::actingAs($owner);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson("/api/mobile/v1/conversations/{$conversation->id}/takeover")
            ->assertOk()
            ->assertJsonPath('data.assigned_user_id', $owner->id)
            ->assertJsonPath('data.assigned_worker_engagement_id', null);
    }

    public function test_takeover_cancels_mid_flight_ai_generation_and_blocks_queued_ai_outbound(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);
        Queue::fake();

        [$workspace, $conversation, $inbound] = $this->seedHasimInbox();
        $owner = User::query()->findOrFail($workspace->owner_user_id);
        [$worker, $profile] = $this->createWorker('متدخل سريع');
        $this->createEngagement($workspace, $owner, $profile, ['inbox.messages.read', 'inbox.messages.send']);

        Message::withoutGlobalScopes()->whereKey($inbound->id)->update(['content' => 'ما هو السعر؟']);

        $handoff = app(ConversationHandoffService::class);
        $baseAi = $this->mock(AIService::class);
        $baseAi->shouldReceive('generateReply')
            ->once()
            ->andReturnUsing(function () use ($conversation, $handoff, $worker): string {
                // Worker takes over while LLM response is still in flight
                $handoff->takeover($conversation->fresh(), $worker);

                return 'رد AI متأخر يجب إلغاؤه';
            });

        $job = new ProcessAIResponse((int) $conversation->id, (int) $inbound->id, (int) $workspace->id, 'corr-race-cancel', 0, (int) ($conversation->handoff_version ?? 1));
        $job->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $this->assertSame(0, Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->count());

        // Also test queued AI job starting AFTER takeover aborts pre-check cleanly
        $lateJob = new ProcessAIResponse((int) $conversation->id, (int) $inbound->id, (int) $workspace->id, 'corr-late-ai');
        $lateJob->handle(
            app(AIService::class),
            app(ChannelOutboundService::class),
            app(AiReplyEligibility::class),
            app(AiOutputValidator::class),
        );

        $this->assertSame(0, Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('ai_generated', true)
            ->count());

        // Outbound channel dispatcher aborts delivery of already-queued AI message if human took over
        $waOutboundMock = $this->mock(WhatsAppOutboundService::class);
        $waOutboundMock->shouldNotReceive('deliverTextForMessage');

        $aiMsg = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => 'رد قديم',
            'ai_generated' => true,
            'delivery_status' => 'pending',
        ]);
        $delivery = ChannelOutboundDelivery::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $aiMsg->id,
            'channel' => 'whatsapp',
            'status' => ChannelOutboundDelivery::STATUS_QUEUED,
        ]);

        app(WorkspaceContext::class)->runForId($workspace->id, function () use ($delivery): void {
            app(ChannelOutboundService::class)->deliverQueuedDelivery($delivery);
        });

        $this->assertSame(ChannelOutboundDelivery::STATUS_FAILED, $delivery->fresh()->status);
        $this->assertSame('failed', $aiMsg->fresh()->delivery_status);
    }

    public function test_optimized_broadcast_payloads_and_handoff_query_budgets_stay_low(): void
    {
        Event::fake([MessageCreated::class, ConversationUpdated::class]);
        Queue::fake();

        [$owner, $workspace] = $this->createCompany();
        $conversation = $this->createOpenConversation($workspace, 'عميل قياس', aiEnabled: true);
        app(WorkspaceContext::class)->set($workspace);

        $msg = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'قياس السرعة',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $convFresh = $conversation->fresh();
        (new ConversationUpdated($convFresh))->broadcastWith();
        $convPayloadQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        app(InboxRealtimePublisher::class)->publishNewInboundMessage($msg, $conversation);
        $publishQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $handoff = app(ConversationHandoffService::class);
        $handoff->takeover($conversation->fresh(), $owner);
        $handoff->resumeAi($conversation->fresh(), $owner);
        $handoffCycleQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $convPayloadQueries);
        $this->assertLessThanOrEqual(7, $publishQueries);
        $this->assertLessThanOrEqual(35, $handoffCycleQueries);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'شركة اختبار'): array
    {
        $owner = User::factory()->create(['name' => $name.' مالك']);
        $workspace = Workspace::factory()->create([
            'name' => $name,
            'owner_user_id' => $owner->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
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
            'professional_summary' => 'Authorized support worker.',
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
            'job_title' => 'Support Specialist',
            'created_by' => $owner->id,
            'active_at' => now(),
        ]);

        foreach ($capabilities as $capability) {
            WorkerEngagementPermission::query()->create([
                'worker_engagement_id' => $engagement->id,
                'capability_key' => $capability,
                'allowed' => true,
            ]);
        }

        return $engagement;
    }

    private function createOpenConversation(
        Workspace $workspace,
        string $customerName,
        bool $aiEnabled = true,
        string $status = 'open',
    ): Conversation {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $customerName,
            'phone' => '050'.random_int(1000000, 9999999),
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => $status,
            'ai_enabled' => $aiEnabled,
            'handoff_status' => 'none',
            'handoff_version' => 1,
            'last_message_at' => now(),
        ]);
    }
}
