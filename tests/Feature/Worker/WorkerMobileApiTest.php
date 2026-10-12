<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\ConversationHandoffReason;
use App\Enums\WorkerEngagementStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Worker\WorkerDispute;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use Database\Seeders\WorkerCapabilitySeeder;
use Database\Seeders\WorkerSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerMobileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
        $this->seed(WorkerSkillSeeder::class);
    }

    public function test_authenticated_worker_can_read_own_profile_engagement_and_not_private_identity(): void
    {
        [$worker, $profile] = $this->createWorker();
        $profile->privateIdentity()->create([
            'legal_name' => 'Secret Legal',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'ID-SECRET',
            'address' => 'Hidden Address',
        ]);
        [$owner, $workspace] = $this->createCompany();
        $engagement = $this->createEngagement($workspace, $owner, $profile, ['inbox.messages.read']);
        $this->inWorkspace($workspace, function () use ($engagement, $worker): void {
            WorkerDispute::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'reporter_type' => 'worker',
                'reported_by_user_id' => $worker->id,
                'category' => 'access',
                'description' => 'Visible dispute',
                'status' => 'open',
                'review_notes' => 'Internal review note',
                'opened_at' => now(),
            ]);
        });

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.profile'))
            ->assertOk()
            ->assertJsonPath('data.display_name', $profile->display_name)
            ->assertJsonPath('data.verification_status', WorkerProfile::VERIFICATION_APPROVED)
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.identity_number');

        $this->getJson(route('mobile.v1.worker.engagements.show', $engagement))
            ->assertOk()
            ->assertJsonPath('data.id', $engagement->id)
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonMissingPath('data.compensation_terms_json');

        $this->getJson(route('mobile.v1.worker.disputes.index'))
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Visible dispute')
            ->assertJsonMissingPath('data.0.review_notes');
    }

    public function test_worker_cannot_read_another_worker_or_company_engagement(): void
    {
        [$worker] = $this->createWorker('First');
        [, $otherProfile] = $this->createWorker('Second');
        [$owner, $workspace] = $this->createCompany('A');
        [, $otherWorkspace] = $this->createCompany('B');
        $foreign = $this->createEngagement($workspace, $owner, $otherProfile, ['inbox.messages.read']);

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.show', $foreign))->assertNotFound();
        $this->getJson(route('mobile.v1.worker.inbox.index', $otherWorkspace))->assertNotFound();
    }

    public function test_inbox_capability_is_enforced_on_the_worker_api(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);

        Sanctum::actingAs($worker);
        $this->postJson(route('mobile.v1.worker.inbox.messages.store', [$workspace, $conversation]), [
            'content' => 'Allowed message',
        ])->assertCreated()
            ->assertJsonPath('data.participant_type', 'independent_worker')
            ->assertJsonMissingPath('data.ai_employee');

        WorkerEngagementPermission::query()
            ->where('worker_engagement_id', $engagement->id)
            ->where('capability_key', 'inbox.messages.send')
            ->update(['allowed' => false]);

        $this->postJson(route('mobile.v1.worker.inbox.messages.store', [$workspace, $conversation]), [
            'content' => 'Denied message',
        ])->assertForbidden();

        $this->inWorkspace($workspace, function () use ($engagement): void {
            $engagement->update(['status' => WorkerEngagementStatus::Paused]);
        });
        $this->getJson(route('mobile.v1.worker.inbox.index', $workspace))->assertNotFound();
    }

    public function test_unverified_worker_wrong_workspace_and_missing_capability_are_denied(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);
        [, $otherWorkspace] = $this->createCompany('Other');

        Sanctum::actingAs($worker);
        WorkerEngagementPermission::query()
            ->where('worker_engagement_id', $engagement->id)
            ->delete();
        $this->getJson(route('mobile.v1.worker.inbox.index', $workspace))->assertNotFound();
        $this->getJson(route('mobile.v1.worker.inbox.show', [$otherWorkspace, $conversation]))->assertNotFound();

        WorkerEngagementPermission::query()->create([
            'worker_engagement_id' => $engagement->id,
            'capability_key' => 'inbox.messages.read',
            'allowed' => true,
        ]);
        $profile->update([
            'verification_status' => WorkerProfile::VERIFICATION_PENDING_REVIEW,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
        ]);

        $this->getJson(route('mobile.v1.worker.inbox.index', $workspace))->assertNotFound();
    }

    public function test_unauthenticated_worker_api_is_denied(): void
    {
        $this->getJson(route('mobile.v1.worker.profile'))->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(string $name = 'Mobile Worker'): array
    {
        $worker = User::factory()->create(['name' => $name]);
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
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Mobile Workspace'): array
    {
        $owner = User::factory()->create();
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
            'job_title' => 'Specialist',
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

    private function assignedConversation(
        Workspace $workspace,
        User $owner,
        User $worker,
        WorkerEngagement $engagement,
    ): Conversation {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Customer',
            'email' => 'secret@example.test',
            'phone' => '0500000000',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'manual:'.uniqid(),
            'status' => 'open',
            'ai_enabled' => false,
        ]);
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
}
