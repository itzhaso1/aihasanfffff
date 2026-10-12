<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\AI\Employees\EmployeeStatus;
use App\AI\Employees\Models\AiEmployee;
use App\Enums\ConversationHandoffReason;
use App\Enums\WorkerActivityEventType;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkerPayoutStatus;
use App\Enums\WorkforceParticipantType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerActivityEvent;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerPayoutRecord;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Models\Workspace;
use App\Services\Workforce\WorkforceInboxAttribution;
use Database\Seeders\WorkerCapabilitySeeder;
use Database\Seeders\WorkerSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkerWorkforceAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(WorkerCapabilitySeeder::class);
        $this->seed(WorkerSkillSeeder::class);
    }

    public function test_worker_company_and_platform_admin_complete_the_engagement_lifecycle(): void
    {
        $paymentsBefore = DB::table('payments')->count();
        $admin = PlatformAdmin::factory()->create(['name' => 'UAT Platform Admin']);
        [$ownerA, $workspaceA] = $this->createCompany('Company A');
        [$ownerB, $workspaceB] = $this->createCompany('Company B');
        $aiEmployee = $this->createAiEmployee($workspaceA, $ownerA);

        $this->get(route('worker.register'))->assertOk();
        $workerA = $this->registerWorker('Worker A UAT', 'worker-a-uat@example.test', '+962790000001');
        $profileA = $workerA->workerProfile()->firstOrFail();
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $workerA->id]);

        $this->logoutWeb();
        $workerB = $this->registerWorker('Worker B UAT', 'worker-b-uat@example.test', '+962790000002');
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $workerB->id]);

        $this->completeProfile($workerA, $profileA, 'SECRET-UAT-A', discoverable: false);
        $workerA->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($workerA)
            ->post(route('worker.documents.store'), [
                'document_type_code' => 'identity_document',
                'document' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();
        $this->actingAs($workerA)
            ->post(route('worker.verification.submit'))
            ->assertRedirect();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-verifications.approve', $profileA), [
                'notes' => 'UAT approval note',
            ])
            ->assertRedirect();
        $profileA->refresh();
        $this->assertSame(WorkerProfile::VERIFICATION_APPROVED, $profileA->verification_status);
        $this->assertSame(WorkerProfile::LIFECYCLE_VERIFIED, $profileA->lifecycle_status);

        $this->completeProfile($workerA, $profileA, 'SECRET-UAT-A', discoverable: true);
        $skill = WorkerSkill::query()->where('skill_key', 'sales')->firstOrFail();
        $this->actingAs($workerA)
            ->put(route('worker.skills.sync'), ['skill_ids' => [$skill->id]])
            ->assertRedirect();
        $this->actingAs($workerA)
            ->put(route('worker.languages.sync'), ['language_codes' => ['ar']])
            ->assertRedirect();
        $this->actingAs($workerA)
            ->post(route('worker.experiences.store'), [
                'title' => 'Sales Specialist',
                'organization_name' => 'UAT Retail',
                'started_on' => '2024-01-01',
                'is_current' => true,
                'description' => 'Handled retail conversations.',
            ])
            ->assertRedirect();

        $this->companyRequest($ownerA, $workspaceA)
            ->get(route('workspace.worker-discovery.index', ['skill_key' => 'sales']))
            ->assertOk()
            ->assertSee('Worker A UAT')
            ->assertDontSee('Worker B UAT')
            ->assertDontSee('SECRET-UAT-A')
            ->assertDontSee('UAT approval note');
        $this->companyRequest($ownerA, $workspaceA)
            ->get(route('workspace.worker-discovery.show', $profileA))
            ->assertOk()
            ->assertSee('Sales Specialist')
            ->assertDontSee('SECRET-UAT-A');

        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-discovery.engagements.store', $profileA), [
                'job_title' => 'UAT Inbox Specialist',
            ])
            ->assertRedirect();
        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->where('worker_profile_id', $profileA->id)
            ->firstOrFail();
        $this->assertSame(WorkerEngagementStatus::Offered, $engagement->status);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $workerA->id]);

        $this->actingAs($workerB)
            ->get(route('worker.engagements.show', $engagement->id))
            ->assertNotFound();
        $this->actingAs($workerA)
            ->post(route('worker.engagements.accept', $engagement->id))
            ->assertRedirect();
        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.activate', $engagement))
            ->assertRedirect();
        $engagement->refresh();
        $this->assertSame(WorkerEngagementStatus::Active, $engagement->status);

        foreach (['inbox.messages.read', 'inbox.messages.send'] as $capability) {
            $this->companyRequest($ownerA, $workspaceA)
                ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                    'capability_key' => $capability,
                ])
                ->assertRedirect();
        }

        $this->actingAs($workerA)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $conversation = $this->createConversation($workspaceA, $aiEmployee);
        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.conversations.handoff.request', $conversation), [
                'reason' => ConversationHandoffReason::AgentRequested->value,
            ])
            ->assertRedirect();
        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.conversations.handoff.assign', $conversation), [
                'worker_engagement_id' => $engagement->id,
            ])
            ->assertRedirect();

        $this->actingAs($workerA)
            ->post(route('worker.inbox.messages.store', [$workspaceA, $conversation]), [
                'content' => 'UAT worker reply',
            ])
            ->assertRedirect();

        $conversation->refresh();
        $message = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('content', 'UAT worker reply')
            ->firstOrFail();
        $this->assertSame($engagement->id, $conversation->assigned_worker_engagement_id);
        $this->assertSame($aiEmployee->id, $conversation->ai_employee_id);
        $this->assertSame($engagement->id, $message->worker_engagement_id);
        $this->assertFalse((bool) $message->ai_generated);
        $this->assertSame(
            WorkforceParticipantType::IndependentWorker,
            WorkforceInboxAttribution::typeForMessage($message),
        );
        $this->assertSame(
            WorkforceParticipantType::IndependentWorker,
            WorkforceInboxAttribution::typeForConversation($conversation->fresh()),
        );
        $this->assertDatabaseHas('worker_activity_events', [
            'worker_engagement_id' => $engagement->id,
            'actor_user_id' => $workerA->id,
            'event_type' => WorkerActivityEventType::SessionStarted->value,
        ]);
        $this->assertDatabaseHas('worker_activity_events', [
            'worker_engagement_id' => $engagement->id,
            'actor_user_id' => $workerA->id,
            'event_type' => WorkerActivityEventType::ConversationAssigned->value,
        ]);
        $this->assertDatabaseHas('worker_activity_events', [
            'worker_engagement_id' => $engagement->id,
            'actor_user_id' => $workerA->id,
            'event_type' => WorkerActivityEventType::MessageSent->value,
        ]);
        $this->assertSame(0, DB::table('ai_employee_tool_permissions')->count());
        $aiEmployee->refresh();
        $this->assertSame(EmployeeStatus::Active, $aiEmployee->status);

        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.earning-periods.store', $engagement), [
                'starts_at' => '2026-09-01 00:00:00',
                'ends_at' => '2026-09-30 23:59:59',
            ])
            ->assertRedirect();
        $period = WorkerEarningPeriod::withoutGlobalScopes()
            ->where('worker_engagement_id', $engagement->id)
            ->firstOrFail();
        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.earning-periods.lines.store', [$engagement, $period]), [
                'line_type' => 'base',
                'source_type' => 'engagement_terms',
                'amount_minor' => '12500',
                'currency' => 'SAR',
            ])
            ->assertRedirect();
        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.earning-periods.submit', [$engagement, $period]))
            ->assertRedirect();
        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.earning-periods.approve', [$engagement, $period]))
            ->assertRedirect();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-payouts.store'), ['period_id' => $period->id])
            ->assertRedirect();
        $payout = WorkerPayoutRecord::withoutGlobalScopes()
            ->where('worker_earning_period_id', $period->id)
            ->firstOrFail();
        $this->actingAs($admin, 'platform_admin')
            ->patch(route('platform.worker-payouts.update', $payout), [
                'status' => WorkerPayoutStatus::Processing->value,
            ])
            ->assertRedirect();
        $this->actingAs($admin, 'platform_admin')
            ->patch(route('platform.worker-payouts.update', $payout), [
                'status' => WorkerPayoutStatus::Paid->value,
                'provider_reference' => 'UAT-REF-1',
            ])
            ->assertRedirect();
        $this->assertSame($paymentsBefore, DB::table('payments')->count());
        $this->assertDatabaseHas('worker_payout_records', [
            'id' => $payout->id,
            'status' => WorkerPayoutStatus::Paid->value,
            'amount_minor' => 12500,
            'currency' => 'SAR',
            'provider_reference' => 'UAT-REF-1',
        ]);

        $this->actingAs($workerA)
            ->post(route('worker.engagements.disputes.store', $engagement->id), [
                'category' => 'access',
                'description' => 'UAT public dispute description',
            ])
            ->assertRedirect();
        $disputeId = (int) DB::table('worker_disputes')->where('worker_engagement_id', $engagement->id)->value('id');
        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-disputes.under-review', $disputeId), [
                'review_notes' => 'UAT internal note must stay private',
            ])
            ->assertRedirect();
        $this->actingAs($workerA)
            ->get(route('worker.disputes.show', $disputeId))
            ->assertOk()
            ->assertSee('UAT public dispute description')
            ->assertDontSee('UAT internal note must stay private');
        $this->companyRequest($ownerA, $workspaceA)
            ->get(route('workspace.worker-disputes.show', $disputeId))
            ->assertOk()
            ->assertSee('UAT public dispute description')
            ->assertDontSee('UAT internal note must stay private');
        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-disputes.show', $disputeId))
            ->assertNotFound();

        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.terminate', $engagement), [
                'reason' => 'UAT engagement completed its acceptance path.',
            ])
            ->assertRedirect();
        $this->assertSame(
            WorkerEngagementStatus::Terminated,
            $engagement->fresh()->status,
        );
        $this->actingAs($workerA)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect()
            ->assertSessionHasErrors('session');
        $this->actingAs($workerA)
            ->post(route('worker.inbox.messages.store', [$workspaceA, $conversation]), [
                'content' => 'Blocked after termination',
            ])
            ->assertNotFound();
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Blocked after termination',
        ]);

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-engagements.show', $engagement))
            ->assertNotFound();
        $this->assertSame(1, WorkerActivityEvent::withoutGlobalScopes()->where('event_type', WorkerActivityEventType::MessageSent)->count());
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $workerA->id]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $workerB->id]);
    }

    private function registerWorker(string $name, string $email, string $phone): User
    {
        $this->post(route('worker.register.store'), [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('worker.inbox.home', absolute: false));

        return User::query()->where('email', $email)->firstOrFail();
    }

    private function completeProfile(
        User $worker,
        WorkerProfile $profile,
        string $identityNumber,
        bool $discoverable,
    ): void {
        $this->actingAs($worker)
            ->patch(route('worker.profile.update'), [
                'display_name' => $profile->display_name ?: $worker->name,
                'country_code' => 'JO',
                'nationality' => 'Jordanian',
                'professional_summary' => 'Independent sales specialist.',
                'sales_experience_level' => 'senior',
                'availability_status' => 'available',
                'is_discoverable' => $discoverable ? '1' : '0',
                'legal_name' => 'Legal '.$worker->name,
                'date_of_birth' => '1990-01-01',
                'identity_number' => $identityNumber,
            ])
            ->assertRedirect();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name): array
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

    private function createAiEmployee(Workspace $workspace, User $owner): AiEmployee
    {
        return $this->inWorkspace($workspace, fn (): AiEmployee => AiEmployee::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Company A Assistant',
            'role' => 'support',
            'status' => EmployeeStatus::Active,
            'created_by' => $owner->id,
        ]));
    }

    private function createConversation(Workspace $workspace, AiEmployee $aiEmployee): Conversation
    {
        return $this->inWorkspace($workspace, function () use ($workspace, $aiEmployee): Conversation {
            $customer = Customer::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'UAT Customer',
                'email' => 'uat-customer-'.$workspace->id.'@example.test',
                'phone' => '0791111111',
            ]);

            return Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'manual',
                'external_id' => 'manual:uat:'.$workspace->id,
                'status' => 'open',
                'ai_enabled' => true,
                'ai_employee_id' => $aiEmployee->id,
            ]);
        });
    }

    private function companyRequest(User $owner, Workspace $workspace): static
    {
        return $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }

    private function logoutWeb(): void
    {
        Auth::guard('web')->logout();
        $this->flushSession();
    }
}
