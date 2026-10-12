<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileWorkerInboxContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_workspace_member_keeps_the_existing_inbox_contract(): void
    {
        [$member, $workspace] = $this->createCompany();
        $conversation = $this->openConversation($workspace, 'Member Customer');
        $this->inWorkspace($workspace, function () use ($conversation): void {
            $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'Member inbound',
            ]);
        });

        Sanctum::actingAs($member);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.customer.name', 'Member Customer')
            ->assertJsonPath('data.0.can_reply', true)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.next_cursor', null);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.can_reply', true);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Member inbound')
            ->assertJsonPath('meta.per_page', 30);

        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages', [
                'content' => 'Member reply',
                'message_type' => 'text',
            ])
            ->assertCreated()
            ->assertJsonPath('data.content', 'Member reply')
            ->assertJsonPath('data.ai_generated', false)
            ->assertJsonPath('data.worker_engagement_id', null)
            ->assertJsonPath('data.participant_type', 'company_staff');
    }

    public function test_worker_uses_the_same_inbox_contract_inside_engagement_scope(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createWorker();
        $readable = $this->createEngagement($workspaceA, $ownerA, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $silent = $this->createEngagement($workspaceA, $ownerA, $profile, []);
        $otherWorkspace = $this->createEngagement($workspaceB, $ownerB, $profile, []);
        $visible = $this->assignedConversation($workspaceA, $ownerA, $worker, $readable, 'Visible Customer');
        $hidden = $this->openConversation($workspaceA, 'Hidden Customer');
        $otherEngagement = $this->openConversation($workspaceA, 'Other Engagement Customer');
        Conversation::withoutGlobalScopes()->whereKey($otherEngagement->id)->update([
            'assigned_user_id' => $worker->id,
            'assigned_worker_engagement_id' => $silent->id,
        ]);
        $foreign = $this->openConversation($workspaceB, 'Foreign Customer');
        $this->inWorkspace($workspaceA, function () use ($visible): void {
            $visible->messages()->create([
                'workspace_id' => $visible->workspace_id,
                'customer_id' => $visible->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'Visible inbound',
            ]);
        });

        $this->assertDatabaseMissing('workspace_users', [
            'workspace_id' => $workspaceA->id,
            'user_id' => $worker->id,
        ]);

        Sanctum::actingAs($worker);

        $list = $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/conversations?search=Visible')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.customer.name', 'Visible Customer')
            ->assertJsonPath('data.0.can_reply', true)
            ->assertJsonPath('meta.per_page', 20);
        $this->assertCount(1, $list->json('data'));

        $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/conversations?search=Hidden')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $hidden->id)
            ->assertJsonPath('data.0.customer.name', 'Hidden Customer');

        $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/conversations/'.$visible->id)
            ->assertOk()
            ->assertJsonPath('data.id', $visible->id)
            ->assertJsonPath('data.channel', $visible->channel)
            ->assertJsonPath('data.can_reply', true);

        $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/conversations/'.$visible->id.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Visible inbound')
            ->assertJsonPath('data.0.conversation_id', $visible->id)
            ->assertJsonPath('meta.per_page', 30);

        $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/conversations/'.$hidden->id)
            ->assertOk()
            ->assertJsonPath('data.id', $hidden->id)
            ->assertJsonPath('data.customer.name', 'Hidden Customer');

        $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/conversations/'.$otherEngagement->id.'/messages')
            ->assertOk();

        $this->inbox($workspaceB)
            ->getJson('/api/mobile/v1/conversations')
            ->assertForbidden();

        $this->inbox($workspaceB)
            ->getJson('/api/mobile/v1/conversations/'.$foreign->id)
            ->assertNotFound()
            ->assertDontSee('Foreign Customer', false);

        $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/home')
            ->assertForbidden();

        $this->flushHeaders()
            ->getJson('/api/mobile/v1/conversations')
            ->assertForbidden();

        $sent = $this->inbox($workspaceA)
            ->postJson('/api/mobile/v1/conversations/'.$visible->id.'/messages', [
                'content' => 'Worker reply',
                'message_type' => 'text',
                'idempotency_key' => 'worker-msg-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.content', 'Worker reply')
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.ai_generated', false)
            ->assertJsonPath('data.worker_engagement_id', $readable->id)
            ->assertJsonPath('data.participant_type', 'independent_worker')
            ->assertJsonPath('data.idempotency_key', 'worker-msg-1');

        $this->inbox($workspaceA)
            ->postJson('/api/mobile/v1/conversations/'.$visible->id.'/messages', [
                'content' => 'Worker reply',
                'message_type' => 'text',
                'idempotency_key' => 'worker-msg-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $sent->json('data.id'));

        $message = Message::withoutGlobalScopes()->findOrFail($sent->json('data.id'));
        $this->assertSame($worker->id, $message->user_id);
        $this->assertFalse($message->ai_generated);
        $this->assertSame($readable->id, $message->worker_engagement_id);
        $this->assertSame(1, Message::withoutGlobalScopes()
            ->where('conversation_id', $visible->id)
            ->where('direction', 'outbound')
            ->count());
        $this->assertNotSame($otherWorkspace->id, $readable->id);
    }

    public function test_worker_without_send_capability_cannot_send(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Read Only Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement, 'Read Only Customer');

        Sanctum::actingAs($worker);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.can_reply', false);

        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages', [
                'content' => 'Not allowed',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'لا تملك صلاحية تنفيذ هذا الإجراء.');

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Not allowed',
        ]);
    }

    public function test_company_can_assign_a_conversation_to_a_worker_engagement_from_mobile(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Assigned Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->openConversation($workspace, 'Assigned Customer');

        $this->inWorkspace($workspace, function () use ($conversation): void {
            app(ConversationHandoffService::class)->request(
                $conversation,
                ConversationHandoffReason::AgentRequested,
                ['requested_by' => 'staff', 'source' => 'mobile-test'],
                queueImmediately: true,
            );
        });

        Sanctum::actingAs($owner);
        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/assign', [
                'worker_engagement_id' => $engagement->id,
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.assigned_worker_engagement_id',
                $engagement->id,
            )
            ->assertJsonPath('data.assigned_participant_type', 'independent_worker');

        $this->inWorkspace($workspace, function () use ($conversation): void {
            $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'رسالة اختبار للموظف',
            ]);
        });

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.last_message.content', 'رسالة اختبار للموظف');

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'رسالة اختبار للموظف');

        $this->assertDatabaseMissing('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $worker->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Inbox Workspace'): array
    {
        $owner = User::factory()->create(['password' => 'password']);
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
    private function createWorker(string $name = 'Inbox Worker'): array
    {
        $worker = User::factory()->create(['name' => $name, 'password' => 'password']);
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_SAUDI_ARABIA,
            'nationality' => 'Saudi',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
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
            'job_title' => 'Inbox Specialist',
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

    private function openConversation(Workspace $workspace, string $customerName): Conversation
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $customerName,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $customerName)).'@example.test',
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'external_id' => 'wa:'.uniqid(),
            'last_message_at' => now(),
            'ai_enabled' => false,
        ]);
    }

    private function assignedConversation(
        Workspace $workspace,
        User $owner,
        User $worker,
        WorkerEngagement $engagement,
        string $customerName,
    ): Conversation {
        $conversation = $this->openConversation($workspace, $customerName);
        $this->inWorkspace($workspace, function () use ($conversation): void {
            app(ConversationHandoffService::class)->request(
                $conversation,
                ConversationHandoffReason::AgentRequested,
                ['requested_by' => 'staff', 'source' => 'test'],
                queueImmediately: true,
            );
        });

        return $this->inWorkspace($workspace, function () use ($conversation, $owner, $worker, $engagement): Conversation {
            return app(ConversationHandoffService::class)
                ->assign($conversation, $worker, $engagement, $owner)
                ->fresh();
        });
    }

    private function inbox(Workspace $workspace): static
    {
        return $this->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
