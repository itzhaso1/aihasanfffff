<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\ConversationHandoffReason;
use App\Enums\WorkerEngagementStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\PlatformAdmin;
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

class WorkerWebChatBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'Leak Identity 99188';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_independent_worker_logs_in_directly_to_chat_and_can_open_it(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Chat Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->assignedConversation($workspace, $owner, $worker, $engagement);

        $this->get(route('worker.profile.edit'));

        $this->post('/login', [
            'email' => $worker->email,
            'password' => 'password',
        ])->assertRedirect(route('worker.inbox.home'));

        $this->actingAs($worker)
            ->get(route('worker.inbox.home'))
            ->assertOk()
            ->assertSee('Customer '.$engagement->id)
            ->assertSee('id="inbox-shell"', false)
            ->assertSee('data-communication-nav="sidebar"', false)
            ->assertSee('المحادثات')
            ->assertDontSee(route('worker.profile.edit'), false)
            ->assertDontSee(route('workspace.ai-settings.edit'), false)
            ->assertDontSee(route('worker.engagements.index'), false)
            ->assertDontSee(route('worker.disputes.index'), false)
            ->assertDontSee(route('workspace.worker-discovery.index'), false)
            ->assertDontSee('/workspace/finance', false)
            ->assertDontSee('/workspace/pos', false);

        $this->actingAs($worker)
            ->get(route('worker.inbox.show', [$workspace, $conversation->id]))
            ->assertOk()
            ->assertSee('Customer '.$engagement->id);
    }

    public function test_forbidden_worker_web_urls_redirect_to_chat_without_sensitive_content(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Hidden Worker');
        $profile->privateIdentity()->create([
            'legal_name' => self::SECRET,
            'date_of_birth' => '1991-02-02',
            'identity_number' => self::SECRET,
            'address' => self::SECRET,
        ]);
        $engagement = $this->createEngagement($workspace, $owner, $profile, []);

        $pages = [
            route('worker.profile.edit'),
            route('worker.engagements.index'),
            route('worker.engagements.show', $engagement),
            route('worker.engagements.sessions.show', $engagement),
            route('worker.engagements.earnings.show', $engagement),
            route('worker.disputes.index'),
            route('workspace.worker-discovery.index'),
            route('workspace.worker-engagements.index'),
            route('platform.worker-verifications.index'),
            route('dashboard'),
        ];

        foreach ($pages as $page) {
            $this->actingAs($worker)
                ->get($page)
                ->assertRedirect(route('worker.inbox.home'))
                ->assertDontSee(self::SECRET, false);
        }

        foreach ([
            url('/workspace/finance'),
            url('/workspace/pos'),
            url('/workspace/pos/cashier'),
        ] as $removedBusinessPage) {
            $this->actingAs($worker)
                ->get($removedBusinessPage)
                ->assertNotFound()
                ->assertDontSee(self::SECRET, false);
        }

        $this->actingAs($worker)
            ->getJson(route('worker.profile.edit'))
            ->assertForbidden()
            ->assertDontSee(self::SECRET, false);

        $this->actingAs($worker)
            ->get(route('worker.inbox.home'))
            ->assertOk()
            ->assertDontSee(self::SECRET, false)
            ->assertDontSee($engagement->job_title, false);
    }

    public function test_company_user_and_platform_admin_keep_workforce_access(): void
    {
        [$owner, $workspace] = $this->createCompany();
        WorkerProfile::query()->create([
            'user_id' => $owner->id,
            'display_name' => 'Owner Who Is Also A Worker',
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_NOT_REQUESTED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_REGISTERED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
        ]);

        $this->post('/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->assertRedirect(route('workspace.dashboard', absolute: false));

        $this->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.worker-discovery.index'))
            ->assertOk();

        $this->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.worker-engagements.index'))
            ->assertOk();

        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.worker-verifications.index'))
            ->assertOk();
    }

    public function test_chat_capability_authorization_still_blocks_read_and_send(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$blocked, $blockedProfile] = $this->createWorker('Blocked Reader');
        [$reader, $readerProfile] = $this->createWorker('Reader Only');
        $blockedEngagement = $this->createEngagement($workspace, $owner, $blockedProfile, []);
        $readEngagement = $this->createEngagement($workspace, $owner, $readerProfile, [
            'inbox.messages.read',
        ]);
        $hidden = $this->assignedConversation($workspace, $owner, $blocked, $blockedEngagement, false);
        $visible = $this->assignedConversation($workspace, $owner, $reader, $readEngagement);

        $this->inWorkspace($workspace, function () use ($hidden): void {
            $hidden->messages()->create([
                'workspace_id' => $hidden->workspace_id,
                'customer_id' => $hidden->customer_id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'Hidden customer message 99188',
            ]);
        });

        $this->actingAs($blocked)
            ->get(route('worker.inbox.show', [$workspace, $hidden->id]))
            ->assertNotFound()
            ->assertDontSee('Hidden customer message 99188', false);

        $this->actingAs($reader)
            ->get(route('worker.inbox.show', [$workspace, $visible->id]))
            ->assertOk()
            ->assertSee('إرسال الرسائل غير مشمول في الصلاحيات الممنوحة لهذا الارتباط.')
            ->assertDontSee('name="content"', false);

        $this->actingAs($reader)
            ->post(route('worker.inbox.messages.store', [$workspace, $visible->id]), [
                'content' => 'Not allowed 99188',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $visible->id,
            'content' => 'Not allowed 99188',
        ]);
    }

    public function test_worker_mobile_profile_api_remains_available(): void
    {
        [$worker, $profile] = $this->createWorker('Mobile Worker');

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.profile'))
            ->assertOk()
            ->assertJsonPath('data.display_name', $profile->display_name);

        $this->actingAs($worker)
            ->get(route('worker.profile.edit'))
            ->assertRedirect(route('worker.inbox.home'));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Boundary Workspace'): array
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
            'job_title' => 'Boundary Role 99188',
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
        bool $throughAuthorization = true,
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

        if (! $throughAuthorization) {
            Conversation::withoutGlobalScopes()->whereKey($conversation->id)->update([
                'assigned_user_id' => $worker->id,
                'assigned_worker_engagement_id' => $engagement->id,
            ]);

            return $conversation->fresh();
        }

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
