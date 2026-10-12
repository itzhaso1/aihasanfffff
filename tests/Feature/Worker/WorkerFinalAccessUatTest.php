<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\AI\Employees\Models\AiEmployee;
use App\Enums\ConversationHandoffReason;
use App\Enums\WorkerEngagementStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerFinalAccessUatTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'UAT Secret 77120';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_non_chat_worker_mutations_redirect_and_do_not_persist(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Mutation Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);

        $mutations = [
            ['patch', route('worker.profile.update'), ['display_name' => self::SECRET]],
            ['put', route('worker.skills.sync'), ['skills' => ['sales']]],
            ['put', route('worker.languages.sync'), ['languages' => ['ar']]],
            ['post', route('worker.experiences.store'), ['title' => self::SECRET]],
            ['post', route('worker.documents.store'), []],
            ['post', route('worker.verification.submit'), []],
            ['post', route('worker.engagements.accept', $engagement), []],
            ['post', route('worker.engagements.sessions.start', $engagement), []],
            ['post', route('worker.engagements.earnings.dispute', [$engagement, 1]), ['reason' => self::SECRET]],
            ['post', route('worker.engagements.disputes.store', $engagement), ['summary' => self::SECRET]],
        ];

        foreach ($mutations as [$method, $url, $payload]) {
            $this->actingAs($worker)
                ->{$method}($url, $payload)
                ->assertRedirect(route('worker.inbox.home'))
                ->assertDontSee(self::SECRET, false);
        }

        $this->actingAs($worker)
            ->patchJson(route('worker.profile.update'), ['display_name' => self::SECRET])
            ->assertForbidden()
            ->assertDontSee(self::SECRET, false);

        $this->assertSame('Mutation Worker', $profile->fresh()->display_name);
        $this->assertDatabaseCount('worker_work_sessions', 0);
        $this->assertDatabaseMissing('worker_disputes', [
            'worker_engagement_id' => $engagement->id,
        ]);
    }

    public function test_finance_pos_company_and_platform_pages_do_not_render_for_a_worker(): void
    {
        [$worker] = $this->createWorker('Isolated Worker');

        $pages = [
            route('workspace.employees.index'),
            route('workspace.worker-discovery.index'),
            route('platform.worker-verifications.index'),
            route('platform.worker-operations.index'),
            route('platform.worker-payouts.index'),
            route('platform.worker-disputes.index'),
        ];

        foreach ($pages as $page) {
            $this->actingAs($worker)
                ->get($page)
                ->assertRedirect(route('worker.inbox.home'))
                ->assertDontSee(self::SECRET, false);
        }

        foreach ([
            url('/workspace/finance'),
            url('/workspace/finance/employees'),
            url('/workspace/finance/invoices'),
            url('/workspace/pos'),
            url('/workspace/pos/cashier'),
            url('/workspace/pos/menu'),
            url('/workspace/pos/kitchen'),
        ] as $removedBusinessPage) {
            $this->actingAs($worker)
                ->get($removedBusinessPage)
                ->assertNotFound()
                ->assertDontSee(self::SECRET, false);
        }
    }

    public function test_chat_home_omits_company_platform_and_finance_navigation(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Nav Worker');
        $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);

        $this->actingAs($worker)
            ->get(route('worker.inbox.home'))
            ->assertOk()
            ->assertSee('المحادثات')
            ->assertSee('تسجيل الخروج')
            ->assertDontSee('اكتشاف العمال', false)
            ->assertDontSee('المالية', false)
            ->assertDontSee('/workspace/finance/employees', false)
            ->assertDontSee('/workspace/pos/cashier', false)
            ->assertDontSee(route('platform.worker-verifications.index'), false)
            ->assertDontSee(route('worker.profile.edit'), false);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.worker-discovery.index'))
            ->assertOk()
            ->assertSee('اكتشاف العمال', false);
    }

    public function test_suspended_worker_cannot_open_sessions_or_send_chat(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Suspended UAT');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-verifications.suspend', $profile), [
                'reason' => 'UAT suspension',
            ])
            ->assertRedirect();

        $this->actingAs($worker)
            ->get(route('worker.engagements.sessions.show', $engagement))
            ->assertRedirect(route('worker.inbox.home'))
            ->assertDontSee('بدء جلسة العمل', false);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement))
            ->assertRedirect(route('worker.inbox.home'));

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [$workspace, $conversation->id]))
            ->assertNotFound()
            ->assertDontSee('Initial message', false);

        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [$workspace, $conversation->id]), [
                'content' => 'Blocked while suspended',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('worker_work_sessions', 0);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Blocked while suspended',
        ]);
        $this->assertSame(WorkerEngagementStatus::Active, $engagement->fresh()->status);
    }

    public function test_terminated_engagement_cannot_send_and_another_company_stays_isolated(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Company A');
        [$ownerB, $workspaceB] = $this->createCompany('Company B');
        [$worker, $profile] = $this->createWorker('Two Company Worker');
        $engagementA = $this->createEngagement($workspaceA, $ownerA, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $engagementB = $this->createEngagement($workspaceB, $ownerB, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversationA = $this->assignedConversation($workspaceA, $ownerA, $worker, $engagementA);
        $conversationB = $this->assignedConversation($workspaceB, $ownerB, $worker, $engagementB);

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.worker-engagements.terminate', $engagementA), [
                'reason' => 'Company A ended the engagement.',
            ])
            ->assertRedirect();

        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [$workspaceA, $conversationA->id]), [
                'content' => 'Reply after termination',
            ])
            ->assertNotFound();

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [$workspaceB, $conversationB->id]))
            ->assertOk()
            ->assertSee('Customer '.$engagementB->id)
            ->assertDontSee('Customer '.$engagementA->id, false);

        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [$workspaceB, $conversationB->id]), [
                'content' => 'Reply for company B',
            ])
            ->assertRedirect(route('worker.inbox.show', [$workspaceB, $conversationB->id]));

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversationA->id,
            'content' => 'Reply after termination',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationB->id,
            'content' => 'Reply for company B',
            'user_id' => $worker->id,
            'worker_engagement_id' => $engagementB->id,
            'ai_generated' => false,
        ]);
    }

    public function test_worker_chat_send_does_not_create_an_ai_employee(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Human Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);

        $this->assertSame(0, AiEmployee::withoutGlobalScopes()->count());

        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [$workspace, $conversation->id]), [
                'content' => 'Human worker reply',
                'ai_generated' => true,
            ])
            ->assertRedirect(route('worker.inbox.show', [$workspace, $conversation->id]));

        $message = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('content', 'Human worker reply')
            ->firstOrFail();

        $this->assertFalse($message->ai_generated);
        $this->assertSame($worker->id, $message->user_id);
        $this->assertSame($engagement->id, $message->worker_engagement_id);
        $this->assertSame(0, AiEmployee::withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('ai_employees', [
            'created_by' => $worker->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'UAT Workspace'): array
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
    ): WorkerEngagement {
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'UAT Role',
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
            'name' => 'Customer '.$engagement->id,
            'email' => 'private-'.$workspace->id.'-'.uniqid().'@example.test',
            'phone' => '050000'.uniqid(),
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'manual',
            'external_id' => 'manual:'.$workspace->id.':'.uniqid(),
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $this->inWorkspace($workspace, function () use ($conversation): void {
            app(ConversationHandoffService::class)->request(
                $conversation,
                ConversationHandoffReason::AgentRequested,
                ['requested_by' => 'staff', 'source' => 'test'],
                queueImmediately: true,
            );
        });

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
}
