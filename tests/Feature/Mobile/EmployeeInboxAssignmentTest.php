<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Enums\ConversationHandoffStatus;
use App\Enums\WorkerEngagementStatus;
use App\Models\Conversation;
use App\Models\Customer;
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

class EmployeeInboxAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_open_conversation_can_be_assigned_without_a_prior_handoff_request(): void
    {
        [$owner, $workspace] = $this->company();
        $employee = $this->agent($workspace);
        $conversation = $this->conversation($workspace, 'Customer A');
        $this->message($workspace, $conversation, 'سؤال العميل');

        $this->inWorkspace($workspace, function () use ($conversation, $employee, $owner): void {
            app(ConversationHandoffService::class)->assign($conversation, $employee, actor: $owner);
        });

        $conversation->refresh();
        $this->assertSame($employee->id, $conversation->assigned_user_id);
        $this->assertNull($conversation->assigned_worker_engagement_id);
        $this->assertSame(ConversationHandoffStatus::Assigned, $conversation->handoff_status);

        Sanctum::actingAs($employee);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonFragment(['id' => $conversation->id]);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'سؤال العميل');

        $other = Workspace::factory()->create(['type' => 'company', 'owner_user_id' => $owner->id]);
        $this->inbox($other)
            ->getJson('/api/mobile/v1/conversations')
            ->assertForbidden();
    }

    public function test_worker_with_read_capability_sees_conversation_assigned_to_another_worker(): void
    {
        [$owner, $workspace] = $this->company();
        [$workerA] = $this->worker('Worker A');
        [$workerB] = $this->worker('Worker B');
        $engagementA = $this->engagement($workspace, $owner, $workerA);
        $this->engagement($workspace, $owner, $workerB);
        $conversation = $this->conversation($workspace, 'Assigned Customer');
        $this->message($workspace, $conversation, 'رسالة مسندة');

        $this->inWorkspace($workspace, function () use ($conversation, $workerA, $engagementA, $owner): void {
            app(ConversationHandoffService::class)->assign($conversation, $workerA, $engagementA, $owner);
        });

        $conversation->refresh();
        $this->assertSame($workerA->id, $conversation->assigned_user_id);
        $this->assertSame($engagementA->id, $conversation->assigned_worker_engagement_id);

        Sanctum::actingAs($workerA);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.assigned_worker_engagement_id', $engagementA->id);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'رسالة مسندة');

        Sanctum::actingAs($workerB);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id);

        $other = Workspace::factory()->create(['type' => 'company']);
        $this->inbox($other)
            ->getJson('/api/mobile/v1/conversations')
            ->assertForbidden();
    }

    public function test_worker_with_read_capability_sees_an_unassigned_open_conversation(): void
    {
        [$owner, $workspace] = $this->company();
        [$worker] = $this->worker('Worker Unassigned');
        $this->engagement($workspace, $owner, $worker, ['inbox.messages.read', 'inbox.messages.send']);
        $conversation = $this->conversation($workspace, 'Unassigned Customer');
        $this->message($workspace, $conversation, 'رسالة غير مسندة');

        $conversation->refresh();
        $this->assertNull($conversation->assigned_user_id);
        $this->assertNull($conversation->assigned_worker_engagement_id);
        $this->assertSame(ConversationHandoffStatus::None, $conversation->handoff_status);

        Sanctum::actingAs($worker);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id);
    }

    public function test_mobile_assign_of_open_conversation_lets_worker_read_and_send(): void
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
        $this->message($workspace, $conversation, 'رسالة مسندة');

        Sanctum::actingAs($owner);
        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/assign', [
                'worker_engagement_id' => $engagementA->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.assigned_user_id', $workerA->id)
            ->assertJsonPath('data.assigned_worker_engagement_id', $engagementA->id);

        $conversation->refresh();
        $this->assertSame($workerA->id, $conversation->assigned_user_id);
        $this->assertSame($engagementA->id, $conversation->assigned_worker_engagement_id);
        $this->assertSame(ConversationHandoffStatus::Assigned, $conversation->handoff_status);

        Sanctum::actingAs($workerA);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id);

        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages')
            ->assertOk()
            ->assertJsonPath('data.0.content', 'رسالة مسندة');

        $this->inbox($workspace)
            ->postJson('/api/mobile/v1/conversations/'.$conversation->id.'/messages', [
                'content' => 'رد العامل',
                'message_type' => 'text',
            ])
            ->assertCreated()
            ->assertJsonPath('data.content', 'رد العامل')
            ->assertJsonPath('data.worker_engagement_id', $engagementA->id);

        Sanctum::actingAs($workerB);
        $this->inbox($workspace)
            ->getJson('/api/mobile/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $conversation->id);

        $other = Workspace::factory()->create(['type' => 'company']);
        Sanctum::actingAs($workerA);
        $this->inbox($other)
            ->getJson('/api/mobile/v1/conversations')
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function company(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
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

    private function agent(Workspace $workspace): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
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
        array $capabilities = ['inbox.messages.read'],
    ): WorkerEngagement {
        $profile = WorkerProfile::query()->where('user_id', $worker->id)->firstOrFail();
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Inbox',
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
            'ai_enabled' => true,
            'handoff_status' => 'none',
        ]);
    }

    private function message(Workspace $workspace, Conversation $conversation, string $content): void
    {
        $this->inWorkspace($workspace, function () use ($conversation, $content): void {
            $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => $content,
            ]);
        });
    }

    private function inbox(Workspace $workspace): static
    {
        return $this->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
