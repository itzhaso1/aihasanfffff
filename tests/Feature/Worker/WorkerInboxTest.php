<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\ConversationHandoffReason;
use App\Enums\WorkerEngagementStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_authorized_company_user_can_assign_an_eligible_worker(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Independent Assignee');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->createConversation($workspace, 'Assignment Customer');
        $this->requestHandoff($workspace, $conversation);

        $response = $this->companyRequest($owner, $workspace)->post(
            route('workspace.conversations.handoff.assign', $conversation),
            ['worker_engagement_id' => $engagement->id],
        );

        $response->assertRedirect();
        $conversation->refresh();
        $this->assertSame($worker->id, $conversation->assigned_user_id);
        $this->assertSame($engagement->id, $conversation->assigned_worker_engagement_id);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.inbox.conversation.assigned',
            'entity_id' => $conversation->id,
        ]);
        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('عامل مستقل')
            ->assertSee('Independent Assignee');
    }

    public function test_worker_with_read_capability_can_read_a_conversation_in_the_workspace(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Assigned Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);
        $privateEmail = Customer::withoutGlobalScopes()
            ->findOrFail($conversation->customer_id)
            ->email;
        $this->inWorkspace($workspace, function () use ($conversation): void {
            $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'Visible customer message',
            ]);
        });

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [
                'workspace' => $workspace->id,
                'conversation' => $conversation->id,
            ]))
            ->assertOk()
            ->assertSee('Visible customer message')
            ->assertSee('Assigned Worker')
            ->assertDontSee($privateEmail);
    }

    public function test_worker_with_read_capability_sees_an_unassigned_conversation(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Unassigned Inbox Worker');
        $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->createConversation($workspace, 'Unassigned Customer');
        $this->inWorkspace($workspace, function () use ($conversation): void {
            $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'Visible unassigned message',
            ]);
        });

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [
                'workspace' => $workspace->id,
                'conversation' => $conversation->id,
            ]))
            ->assertOk()
            ->assertSee('Visible unassigned message')
            ->assertSee('Unassigned Customer');
    }

    public function test_worker_cannot_read_a_conversation_from_another_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createWorker('Shared Worker');
        $engagementA = $this->createEngagement($workspaceA, $ownerA, $profile, [
            'inbox.messages.read',
        ]);
        $engagementB = $this->createEngagement($workspaceB, $ownerB, $profile, [
            'inbox.messages.read',
        ]);
        $conversationA = $this->assignedConversation($workspaceA, $ownerA, $worker, $engagementA);
        $conversationB = $this->assignedConversation($workspaceB, $ownerB, $worker, $engagementB);

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [
                'workspace' => $workspaceA->id,
                'conversation' => $conversationB->id,
            ]))
            ->assertNotFound();

        [$otherWorker, $otherProfile] = $this->createWorker('Other Worker');
        $otherEngagement = $this->createEngagement($workspaceA, $ownerA, $otherProfile, [
            'inbox.messages.read',
        ]);
        $otherConversation = $this->assignedConversation(
            $workspaceA,
            $ownerA,
            $otherWorker,
            $otherEngagement,
        );

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [
                'workspace' => $workspaceA->id,
                'conversation' => $otherConversation->id,
            ]))
            ->assertOk()
            ->assertSee('Customer '.$otherEngagement->id);
    }

    public function test_worker_message_is_attributed_to_the_user_and_immutable_engagement_context(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Message Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [$workspace, $conversation->id]))
            ->assertOk()
            ->assertSee('name="content"', false);

        $response = $this->actingAs($worker)->post(
            route('worker.inbox.messages.store', [
                'workspace' => $workspace->id,
                'conversation' => $conversation->id,
            ]),
            [
                'content' => 'Reply from the independent worker',
                'workspace_id' => 999999,
                'worker_engagement_id' => 999999,
                'user_id' => $owner->id,
            ],
        );

        $response->assertRedirect();
        $message = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('content', 'Reply from the independent worker')
            ->firstOrFail();

        $this->assertSame($worker->id, $message->user_id);
        $this->assertSame($engagement->id, $message->worker_engagement_id);
        $this->assertFalse($message->ai_generated);
        $this->assertSame('outbound', $message->direction);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.inbox.message.sent',
            'entity_id' => $message->id,
        ]);
    }

    public function test_worker_engagement_page_explains_missing_inbox_read_permission_and_shows_capability_names(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$blockedWorker, $blockedProfile] = $this->createWorker('Blocked Inbox Worker');
        [$grantedWorker, $grantedProfile] = $this->createWorker('Granted Inbox Worker');
        $blocked = $this->createEngagement($workspace, $owner, $blockedProfile, []);
        $granted = $this->createEngagement($workspace, $owner, $grantedProfile, [
            'inbox.messages.read',
        ]);

        $this->actingAs($blockedWorker)
            ->get(route('worker.engagements.show', $blocked->id))
            ->assertRedirect(route('worker.inbox.home'))
            ->assertDontSee(route('worker.inbox.index', $workspace->id), false)
            ->assertDontSee('inbox.messages.read', false);

        $this->actingAs($grantedWorker)
            ->get(route('worker.engagements.show', $granted->id))
            ->assertRedirect(route('worker.inbox.home'))
            ->assertDontSee('Read inbox messages', false);
    }

    public function test_worker_cannot_send_without_the_send_capability(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Read Only Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [$workspace, $conversation->id]))
            ->assertOk()
            ->assertSee('إرسال الرسائل غير مشمول في الصلاحيات الممنوحة لهذا الارتباط.')
            ->assertDontSee('name="content"', false)
            ->assertDontSee(route('worker.inbox.messages.store', [$workspace, $conversation->id]), false);

        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [
                'workspace' => $workspace->id,
                'conversation' => $conversation->id,
            ]), [
                'content' => 'Not allowed',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Not allowed',
        ]);
    }

    public function test_existing_staff_takeover_replaces_worker_assignment_without_merging_identities(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Handoff Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);

        $this->inWorkspace($workspace, function () use ($conversation, $owner): void {
            app(ConversationHandoffService::class)->takeover($conversation, $owner);
        });

        $conversation->refresh();
        $this->assertSame($owner->id, $conversation->assigned_user_id);
        $this->assertNull($conversation->assigned_worker_engagement_id);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.inbox.conversation.unassigned',
            'entity_id' => $conversation->id,
        ]);
    }

    public function test_company_cannot_assign_cross_workspace_or_inactive_engagement(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createWorker('Assignment Boundary Worker');
        $engagementB = $this->createEngagement($workspaceB, $ownerB, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->createConversation($workspaceA, 'Workspace A Customer');
        $this->requestHandoff($workspaceA, $conversation);

        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.conversations.handoff.assign', $conversation), [
                'worker_engagement_id' => $engagementB->id,
            ])
            ->assertSessionHasErrors('worker_engagement_id');

        $inactiveEngagement = $this->createEngagement(
            $workspaceA,
            $ownerA,
            $profile,
            ['inbox.messages.read'],
            WorkerEngagementStatus::Paused,
        );

        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.conversations.handoff.assign', $conversation), [
                'worker_engagement_id' => $inactiveEngagement->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $conversation->refresh();
        $this->assertNull($conversation->assigned_worker_engagement_id);
        $this->assertNull($conversation->assigned_user_id);
    }

    public function test_same_worker_has_isolated_inboxes_for_multiple_engagements(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createWorker('Multi Company Worker');
        $engagementA = $this->createEngagement($workspaceA, $ownerA, $profile, [
            'inbox.messages.read',
        ]);
        $engagementB = $this->createEngagement($workspaceB, $ownerB, $profile, [
            'inbox.messages.read',
        ]);
        $conversationA = $this->assignedConversation($workspaceA, $ownerA, $worker, $engagementA);
        $conversationB = $this->assignedConversation($workspaceB, $ownerB, $worker, $engagementB);

        $this->actingAs($worker)
            ->get(route('worker.inbox.index', $workspaceA->id))
            ->assertOk()
            ->assertSee('Customer '.$engagementA->id)
            ->assertDontSee('Customer '.$engagementB->id);

        $this->actingAs($worker)
            ->get(route('worker.inbox.index', $workspaceB->id))
            ->assertOk()
            ->assertSee('Customer '.$engagementB->id)
            ->assertDontSee('Customer '.$engagementA->id);
    }

    public function test_worker_uses_the_main_inbox_and_read_capability_gates_content(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Shared Inbox Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);
        $hidden = $this->createConversation($workspace, 'Hidden From Worker');

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.conversations.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('id="inbox-shell"', false)
            ->assertSee('تولي المحادثة', false)
            ->assertSee(route('workspace.conversations.messages.store', $conversation), false);

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [$workspace, $conversation->id]))
            ->assertOk()
            ->assertSee('id="inbox-shell"', false)
            ->assertSee('data-inbox-conversation-list', false)
            ->assertSee('name="content"', false)
            ->assertSee('Customer '.$engagement->id)
            ->assertSee('Hidden From Worker')
            ->assertDontSee('تولي المحادثة', false)
            ->assertDontSee(route('workspace.conversations.messages.store', $conversation), false)
            ->assertDontSee(route('workspace.conversations.handoff.takeover', $conversation), false);

        [$blockedWorker, $blockedProfile] = $this->createWorker('No Read Worker');
        $this->createEngagement($workspace, $owner, $blockedProfile, []);

        $this->actingAs($blockedWorker)
            ->get(route('worker.inbox.home'))
            ->assertOk()
            ->assertSee('id="inbox-shell"', false)
            ->assertSee('data-communication-nav="sidebar"', false)
            ->assertSee('ابدأ بإدارة محادثاتك', false)
            ->assertDontSee('لا توجد محادثات مسندة إليك حالياً', false)
            ->assertDontSee('Customer '.$engagement->id)
            ->assertDontSee('Hidden From Worker');

        $this->actingAs($blockedWorker)
            ->get(route('worker.inbox.index', $workspace))
            ->assertOk()
            ->assertSee('id="inbox-shell"', false)
            ->assertDontSee('Customer '.$engagement->id);

        $this->actingAs($blockedWorker)
            ->post(route('worker.inbox.messages.store', [$workspace, $conversation->id]), [
                'content' => 'Blocked send',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Blocked send',
        ]);
        $this->assertNotSame($hidden->id, $conversation->id);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Inbox Workspace'): array
    {
        $owner = User::factory()->create(['name' => $name.' Owner']);
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
            'professional_summary' => 'Professional independent worker.',
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
        WorkerEngagementStatus $status = WorkerEngagementStatus::Active,
    ): WorkerEngagement {
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => $status,
            'job_title' => 'Inbox Specialist',
            'created_by' => $owner->id,
            'active_at' => $status === WorkerEngagementStatus::Active ? now() : null,
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

    private function createConversation(Workspace $workspace, string $customerName): Conversation
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $customerName,
            'email' => 'private-'.$workspace->id.'-'.uniqid().'@example.test',
            'phone' => '050000'.uniqid(),
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'manual:'.$workspace->id.':'.uniqid(),
            'status' => 'open',
            'ai_enabled' => true,
        ]);
    }

    private function requestHandoff(Workspace $workspace, Conversation $conversation): void
    {
        $this->inWorkspace($workspace, function () use ($conversation): void {
            app(ConversationHandoffService::class)->request(
                $conversation,
                ConversationHandoffReason::AgentRequested,
                ['requested_by' => 'staff', 'source' => 'test'],
                queueImmediately: true,
            );
        });
    }

    private function assignedConversation(
        Workspace $workspace,
        User $owner,
        User $worker,
        WorkerEngagement $engagement,
    ): Conversation {
        $conversation = $this->createConversation($workspace, 'Customer '.$engagement->id);
        $this->requestHandoff($workspace, $conversation);

        return $this->inWorkspace($workspace, function () use (
            $conversation,
            $owner,
            $worker,
            $engagement,
        ): Conversation {
            return app(ConversationHandoffService::class)
                ->assign($conversation, $worker, $engagement, $owner)
                ->fresh();
        });
    }

    private function companyRequest(User $owner, Workspace $workspace): static
    {
        return $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
