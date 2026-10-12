<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\ConversationHandoffReason;
use App\Enums\WorkerActivityEventType;
use App\Enums\WorkerEngagementStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Worker\WorkerActivityEvent;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Worker\WorkerActivityEventException;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerOperationalActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_sending_an_inbox_message_records_an_engagement_scoped_event(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);

        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [$workspace, $conversation]), [
                'content' => 'Hello from the worker',
            ])
            ->assertRedirect();

        $event = WorkerActivityEvent::withoutGlobalScopes()
            ->where('event_type', WorkerActivityEventType::MessageSent)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($engagement->id, $event->worker_engagement_id);
        $this->assertSame($worker->id, $event->actor_user_id);
        $this->assertSame($conversation->id, $event->payload['conversation_id']);
    }

    public function test_assigning_a_worker_records_conversation_assigned_for_that_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->createConversation($workspace);
        $this->requestHandoff($workspace, $conversation);

        $this->inWorkspace($workspace, function () use ($conversation, $worker, $engagement, $owner): void {
            app(ConversationHandoffService::class)->assign($conversation, $worker, $engagement, $owner);
        });

        $this->assertDatabaseHas('worker_activity_events', [
            'worker_engagement_id' => $engagement->id,
            'actor_user_id' => $worker->id,
            'event_type' => WorkerActivityEventType::ConversationAssigned->value,
            'subject_id' => $conversation->id,
        ]);
    }

    public function test_unauthorized_send_and_presence_noise_do_not_create_activity(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);
        $before = WorkerActivityEvent::withoutGlobalScopes()->count();

        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [$workspace, $conversation]), [
                'content' => 'Should be denied',
            ])
            ->assertForbidden();

        $this->actingAs($worker)
            ->post('/workers/activity', [
                'event_type' => 'page_view',
                'occurred_at' => '2000-01-01T00:00:00Z',
            ])
            ->assertNotFound();

        $this->assertSame($before, WorkerActivityEvent::withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('worker_activity_events', [
            'event_type' => 'page_view',
        ]);
    }

    public function test_activity_records_cannot_be_updated_or_deleted(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->createConversation($workspace);
        $this->requestHandoff($workspace, $conversation);
        $this->inWorkspace($workspace, function () use ($conversation, $worker, $engagement, $owner): void {
            app(ConversationHandoffService::class)->assign($conversation, $worker, $engagement, $owner);
        });

        $event = WorkerActivityEvent::withoutGlobalScopes()->firstOrFail();

        $this->expectException(WorkerActivityEventException::class);
        $this->inWorkspace($workspace, function () use ($event): void {
            $event->update(['payload' => ['tampered' => true]]);
        });
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
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
    private function createWorker(): array
    {
        $worker = User::factory()->create();
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => 'Active Worker',
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
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

    private function createConversation(Workspace $workspace): Conversation
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Customer',
            'email' => 'hidden-'.$workspace->id.'@example.test',
            'phone' => '0790000000',
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
        $conversation = $this->createConversation($workspace);
        $this->requestHandoff($workspace, $conversation);

        return $this->inWorkspace($workspace, function () use ($conversation, $owner, $worker, $engagement): Conversation {
            return app(ConversationHandoffService::class)
                ->assign($conversation, $worker, $engagement, $owner)
                ->fresh();
        });
    }
}
