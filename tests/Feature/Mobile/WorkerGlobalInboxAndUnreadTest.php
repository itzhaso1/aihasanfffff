<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

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

class WorkerGlobalInboxAndUnreadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_worker_with_read_capability_sees_unassigned_conversation(): void
    {
        [$owner, $workspace] = $this->company();
        [$worker] = $this->worker('Reader');
        $this->engagement($workspace, $owner, $worker, ['inbox.messages.read', 'inbox.messages.send']);
        $conversation = $this->conversation($workspace, 'Unassigned Customer');
        $this->inbound($workspace, $conversation, 'رسالة غير مسندة');

        $conversation->refresh();
        $this->assertNull($conversation->assigned_user_id);
        $this->assertNull($conversation->assigned_worker_engagement_id);

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.customer.name', 'Unassigned Customer');

        $this->assertDatabaseMissing('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $worker->id,
        ]);
    }

    public function test_worker_with_read_capability_sees_conversation_assigned_to_another_worker(): void
    {
        [$owner, $workspace] = $this->company();
        [$workerA] = $this->worker('Worker A');
        [$workerB] = $this->worker('Worker B');
        $engagementA = $this->engagement($workspace, $owner, $workerA, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $this->engagement($workspace, $owner, $workerB, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->conversation($workspace, 'Assigned Customer');
        $this->inbound($workspace, $conversation, 'رسالة مسندة');
        $this->inWorkspace($workspace, function () use ($conversation, $workerA, $engagementA, $owner): void {
            app(ConversationHandoffService::class)->assign($conversation, $workerA, $engagementA, $owner);
        });

        Sanctum::actingAs($workerB);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.assigned_user_id', $workerA->id);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'رسالة مسندة');
    }

    public function test_worker_without_read_capability_cannot_see_conversations(): void
    {
        [$owner, $workspace] = $this->company();
        [$worker] = $this->worker('No Read');
        $this->engagement($workspace, $owner, $worker, []);
        $this->conversation($workspace, 'Hidden Customer');

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertForbidden();
    }

    public function test_returns_403_when_worker_lists_another_company_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->company('Company A');
        [, $workspaceB] = $this->company('Company B');
        [$worker] = $this->worker('Scoped Worker');
        $this->engagement($workspaceA, $ownerA, $worker, ['inbox.messages.read']);
        $this->conversation($workspaceA, 'Company A Customer');
        $this->conversation($workspaceB, 'Company B Customer');

        Sanctum::actingAs($worker);
        $this->inbox($workspaceB)
            ->getJson('/api/mobile/v1/conversations')
            ->assertForbidden();
    }

    public function test_returns_404_when_worker_opens_another_company_conversation(): void
    {
        [$ownerA, $workspaceA] = $this->company('Company A');
        [$ownerB, $workspaceB] = $this->company('Company B');
        [$workerA] = $this->worker('Worker A');
        [$workerB] = $this->worker('Worker B');
        $this->engagement($workspaceA, $ownerA, $workerA, ['inbox.messages.read']);
        $this->engagement($workspaceB, $ownerB, $workerB, ['inbox.messages.read']);
        $foreign = $this->conversation($workspaceB, 'Company B Customer');

        Sanctum::actingAs($workerA);
        $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/conversations/'.$foreign->id)
            ->assertNotFound()
            ->assertDontSee('Company B Customer', false);
    }

    public function test_paused_engagement_cannot_see_workspace_conversations(): void
    {
        [$owner, $workspace] = $this->company();
        [$worker] = $this->worker('Paused Worker');
        $this->engagement(
            $workspace,
            $owner,
            $worker,
            ['inbox.messages.read'],
            WorkerEngagementStatus::Paused,
        );
        $this->conversation($workspace, 'Company Customer');

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertForbidden();
    }

    public function test_opening_conversation_marks_read_and_list_unread_count_is_zero(): void
    {
        [$worker, $workspace, $conversation, $first] = $this->unreadWorkerConversation();

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);

        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertDatabaseHas('conversation_user_states', [
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'user_id' => $worker->id,
            'last_read_message_id' => $first->id,
        ]);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_list_refresh_does_not_restore_unread_count_after_mark_read(): void
    {
        [$worker, $workspace, $conversation] = $this->unreadWorkerConversation();

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_new_inbound_message_creates_unread_count_of_one_then_opening_clears_it(): void
    {
        [$worker, $workspace, $conversation, $first] = $this->unreadWorkerConversation();

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/read')
            ->assertOk();

        $second = $this->inbound($workspace, $conversation, 'رسالة جديدة');

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 1);

        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertDatabaseHas('conversation_user_states', [
            'conversation_id' => $conversation->id,
            'user_id' => $worker->id,
            'last_read_message_id' => $second->id,
        ]);
        $this->assertNotSame($first->id, $second->id);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_unread_counts_are_isolated_per_worker_and_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->company('Company A');
        [$ownerB, $workspaceB] = $this->company('Company B');
        [$workerA] = $this->worker('Worker A');
        [$workerB] = $this->worker('Worker B');
        $this->engagement($workspaceA, $ownerA, $workerA, ['inbox.messages.read']);
        $this->engagement($workspaceB, $ownerB, $workerB, ['inbox.messages.read']);
        $conversationA = $this->conversation($workspaceA, 'A Customer');
        $conversationB = $this->conversation($workspaceB, 'B Customer');
        $this->inbound($workspaceA, $conversationA, 'A inbound');
        $this->inbound($workspaceB, $conversationB, 'B inbound');

        Sanctum::actingAs($workerA);
        $this->inbox($workspaceA)
            ->postJson('/api/mobile/v1/conversations/'.$conversationA->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        Sanctum::actingAs($workerB);
        $this->inbox($workspaceB)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationB->id)
            ->assertJsonPath('data.0.unread_count', 1);

        Sanctum::actingAs($workerA);
        $this->inbox($workspaceA)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_worker_with_send_can_reply_to_unassigned_conversation(): void
    {
        [$owner, $workspace] = $this->company();
        [$worker] = $this->worker('Sender');
        $engagement = $this->engagement($workspace, $owner, $worker, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->conversation($workspace, 'Unassigned Customer');
        $this->inbound($workspace, $conversation, 'سؤال');

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.can_reply', true);

        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages', [
                'content' => 'رد العامل',
                'message_type' => 'text',
            ])
            ->assertCreated()
            ->assertJsonPath('data.content', 'رد العامل')
            ->assertJsonPath('data.worker_engagement_id', $engagement->id);

        $conversation->refresh();
        $this->assertNull($conversation->assigned_user_id);
    }

    public function test_worker_without_send_cannot_reply_even_when_they_can_read(): void
    {
        [$owner, $workspace] = $this->company();
        [$worker] = $this->worker('Read Only');
        $this->engagement($workspace, $owner, $worker, ['inbox.messages.read']);
        $conversation = $this->conversation($workspace, 'Read Only Customer');
        $this->inbound($workspace, $conversation, 'سؤال');

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.can_reply', false);

        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages', [
                'content' => 'Not allowed',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Not allowed',
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Conversation, 3: Message}
     */
    private function unreadWorkerConversation(): array
    {
        [$owner, $workspace] = $this->company();
        [$worker] = $this->worker('Unread Worker');
        $this->engagement($workspace, $owner, $worker, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->conversation($workspace, 'Unread Customer');
        $first = $this->inbound($workspace, $conversation, 'رسالة أولى');

        return [$worker, $workspace, $conversation, $first];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function company(string $name = 'Inbox Workspace'): array
    {
        $owner = User::factory()->create(['name' => $name.' Owner']);
        $workspace = Workspace::factory()->create([
            'name' => $name,
            'type' => 'company',
            'owner_user_id' => $owner->id,
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
    private function worker(string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_SAUDI_ARABIA,
            'nationality' => 'Saudi',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'senior',
        ]);

        return [$user, $profile];
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function engagement(
        Workspace $workspace,
        User $owner,
        User $worker,
        array $capabilities,
        WorkerEngagementStatus $status = WorkerEngagementStatus::Active,
    ): WorkerEngagement {
        $profile = WorkerProfile::query()->where('user_id', $worker->id)->firstOrFail();
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => $status,
            'job_title' => 'Inbox',
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

    private function conversation(Workspace $workspace, string $customerName): Conversation
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $customerName,
            'phone' => '05'.random_int(10000000, 99999999),
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'open',
            'external_id' => 'wa:'.uniqid(),
            'last_message_at' => now(),
            'ai_enabled' => false,
            'handoff_status' => 'none',
        ]);
    }

    private function inbound(Workspace $workspace, Conversation $conversation, string $content): Message
    {
        return $this->inWorkspace($workspace, function () use ($conversation, $content): Message {
            $message = $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => $content,
            ]);
            $conversation->update(['last_message_at' => now()]);

            return $message;
        });
    }

    private function inbox(Workspace $workspace): static
    {
        return $this->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
