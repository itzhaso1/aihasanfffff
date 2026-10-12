<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerActivityEventType;
use App\Enums\WorkerEarningLineType;
use App\Enums\WorkerEarningPeriodStatus;
use App\Enums\WorkerEarningSourceType;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkerPayoutStatus;
use App\Enums\WorkforceParticipantType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerActivityEvent;
use App\Models\Worker\WorkerEarningLine;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerPayoutRecord;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkerOperationalReportingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_company_overview_is_paginated_and_workspace_scoped(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Operations A');
        [$ownerB, $workspaceB] = $this->createCompany('Operations B');
        [, $profileA] = $this->createWorker('Worker A');
        [, $profileB] = $this->createWorker('Worker B');
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $profileA);
        $this->createActiveEngagement($ownerB, $workspaceB, $profileB);

        $response = $this->companyRequest($ownerA, $workspaceA)
            ->get(route('workspace.worker-engagements.index', ['per_page' => 1]));

        $response->assertOk()
            ->assertSee('عامل مستقل')
            ->assertSee($profileA->display_name)
            ->assertDontSee($profileB->display_name);
        $this->assertSame(1, $response->viewData('engagements')->perPage());
        $this->assertSame($engagementA->id, $response->viewData('engagements')->first()->id);
    }

    public function test_company_overview_represents_completed_engagements_without_mutating_them(): void
    {
        [$owner, $workspace] = $this->createCompany('Completed Engagements');
        [, $profile] = $this->createWorker('Completed Worker');
        $engagement = $this->createActiveEngagement($owner, $workspace, $profile);

        $this->inWorkspace($workspace, function () use ($engagement): void {
            $engagement->transitionTo(WorkerEngagementStatus::Completed);
        });

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.index', [
                'status' => WorkerEngagementStatus::Completed->value,
            ]))
            ->assertOk()
            ->assertSee('completed')
            ->assertSee($profile->display_name);

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Completed->value,
        ]);
    }

    public function test_company_detail_combines_authoritative_operational_sources_without_private_identity(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Operational Worker');
        $profile->privateIdentity()->create([
            'legal_name' => 'Private Legal Name',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'PRIVATE-IDENTITY',
        ]);
        $engagement = $this->createActiveEngagement($owner, $workspace, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement))
            ->assertRedirect();
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.end', $engagement))
            ->assertRedirect();

        $conversation = $this->createConversation($workspace, $engagement);
        $this->createEarningRecords($workspace, $engagement);

        $response = $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.show', $engagement));

        $response->assertOk()
            ->assertSee('1 جلسة')
            ->assertSee('1 محادثة')
            ->assertSee('1 رسالة مرسلة')
            ->assertSee('1 فترة')
            ->assertSee('1 سجل')
            ->assertDontSee('Private Legal Name')
            ->assertDontSee('PRIVATE-IDENTITY');
        $response->assertViewHas('participantType', WorkforceParticipantType::IndependentWorker->value);
        $this->assertSame($engagement->id, $conversation->assigned_worker_engagement_id);
    }

    public function test_company_operational_filters_validate_date_range_and_page_size(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createWorker('Filtered Worker');
        $engagement = $this->createActiveEngagement($owner, $workspace, $profile);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.sessions.index', $engagement).'?from=2026-01-01&to=2026-02-15')
            ->assertSessionHasErrors('to');

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.sessions.index', $engagement).'?per_page=100')
            ->assertSessionHasErrors('per_page');
    }

    public function test_worker_operational_pages_are_owned_and_paginated(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Self Service Worker');
        [, $otherProfile] = $this->createWorker('Other Worker');
        $engagement = $this->createActiveEngagement($owner, $workspace, $profile);
        $otherEngagement = $this->createActiveEngagement($owner, $workspace, $otherProfile);

        $response = $this->actingAs($worker)
            ->get(route('worker.engagements.index', ['per_page' => 1]));

        $response->assertOk()
            ->assertSee($workspace->name);
        $this->assertSame(1, $response->viewData('engagements')->perPage());
        $this->assertSame(1, $response->viewData('engagements')->total());

        $this->actingAs($worker)
            ->get(route('worker.engagements.sessions.show', $otherEngagement))
            ->assertNotFound();

        $this->actingAs($worker)
            ->get(route('worker.engagements.sessions.show', $engagement))
            ->assertOk()
            ->assertSee('الأحداث الموثقة');

        $this->actingAs($worker)
            ->get(route('worker.engagements.show', $engagement))
            ->assertOk()
            ->assertSee('القدرات الممنوحة لهذا الارتباط');
    }

    public function test_worker_earnings_are_scoped_to_the_selected_engagement(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Earnings Company A');
        [$ownerB, $workspaceB] = $this->createCompany('Earnings Company B');
        [$worker, $profile] = $this->createWorker('Multi Engagement Worker');
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $profile);
        $engagementB = $this->createActiveEngagement($ownerB, $workspaceB, $profile);
        $periodA = $this->createEarningRecords($workspaceA, $engagementA);
        $periodB = $this->createEarningRecords($workspaceB, $engagementB);

        $response = $this->actingAs($worker)
            ->get(route('worker.engagements.earnings.show', $engagementA));

        $response->assertOk()
            ->assertSee($workspaceA->name)
            ->assertSee("فترة {$periodA->id}")
            ->assertDontSee($workspaceB->name)
            ->assertDontSee("فترة {$periodB->id}");
    }

    public function test_historical_activity_remains_read_only_and_event_filters_are_scoped(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Activity Worker');
        $engagement = $this->createActiveEngagement($owner, $workspace, $profile);
        $event = $this->inWorkspace($workspace, function () use ($engagement, $profile, $worker): WorkerActivityEvent {
            return WorkerActivityEvent::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'worker_profile_id' => $profile->id,
                'actor_user_id' => $worker->id,
                'event_type' => WorkerActivityEventType::SessionStarted,
                'occurred_at' => '2026-09-27 10:00:00',
                'payload' => ['session_status' => 'started'],
            ]);
        });

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.sessions.index', $engagement).'?event_type=session_started')
            ->assertOk()
            ->assertSee('session_started')
            ->assertSee((string) $event->id);

        $this->assertDatabaseHas('worker_activity_events', [
            'id' => $event->id,
            'event_type' => WorkerActivityEventType::SessionStarted->value,
        ]);
    }

    public function test_platform_operations_require_platform_guard_and_paginate_engagements(): void
    {
        [$owner, $workspace] = $this->createCompany('Platform Operations');
        [, $profileA] = $this->createWorker('Platform Worker A');
        [, $profileB] = $this->createWorker('Platform Worker B');
        $this->createActiveEngagement($owner, $workspace, $profileA);
        $this->createActiveEngagement($owner, $workspace, $profileB);
        $admin = PlatformAdmin::factory()->create();

        $response = $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.worker-operations.index', ['per_page' => 1]));

        $response->assertOk()
            ->assertSee($profileB->display_name)
            ->assertSee('نشط')
            ->assertSee(route('platform.worker-verifications.index'), false)
            ->assertSee(route('platform.worker-payouts.index'), false);
        $this->assertSame(1, $response->viewData('engagements')->perPage());
        $this->assertSame(2, $response->viewData('engagements')->total());

        auth('platform_admin')->logout();

        $this->actingAs($owner)
            ->getJson(route('platform.worker-operations.index'))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Operations Workspace'): array
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
            'professional_summary' => 'Verified professional worker.',
            'sales_experience_level' => 'senior',
        ]);

        return [$worker, $profile];
    }

    private function createActiveEngagement(
        User $owner,
        Workspace $workspace,
        WorkerProfile $profile,
    ): WorkerEngagement {
        return $this->inWorkspace($workspace, fn (): WorkerEngagement => WorkerEngagement::query()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Operations Specialist',
            'created_by' => $owner->id,
            'active_at' => now(),
        ]));
    }

    private function createConversation(
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): Conversation {
        $customer = $this->inWorkspace($workspace, function () use ($workspace): Customer {
            return Customer::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Operational Customer',
                'email' => 'private@example.test',
                'phone' => '0500000000',
            ]);
        });

        $conversation = $this->inWorkspace($workspace, function () use ($customer, $engagement, $workspace): Conversation {
            return Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'manual',
                'status' => 'open',
                'ai_enabled' => false,
                'assigned_user_id' => $engagement->workerProfile->user_id,
                'assigned_worker_engagement_id' => $engagement->id,
            ]);
        });

        $this->inWorkspace($workspace, function () use ($conversation, $customer, $engagement): void {
            Message::query()->create([
                'workspace_id' => $conversation->workspace_id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'user_id' => $engagement->workerProfile->user_id,
                'worker_engagement_id' => $engagement->id,
                'direction' => 'outbound',
                'message_type' => 'text',
                'content' => 'Worker message',
                'ai_generated' => false,
            ]);
        });

        return $conversation;
    }

    private function createEarningRecords(
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): WorkerEarningPeriod {
        $period = $this->inWorkspace($workspace, function () use ($engagement): WorkerEarningPeriod {
            return WorkerEarningPeriod::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'status' => WorkerEarningPeriodStatus::Draft,
                'starts_at' => '2026-09-01 00:00:00',
                'ends_at' => '2026-09-30 23:59:59',
            ]);
        });

        $this->inWorkspace($workspace, function () use ($engagement, $period): void {
            WorkerEarningLine::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'worker_earning_period_id' => $period->id,
                'line_type' => WorkerEarningLineType::Base,
                'source_type' => WorkerEarningSourceType::EngagementTerms,
                'amount_minor' => 10000,
                'currency' => 'SAR',
            ]);

            $period->transitionTo(WorkerEarningPeriodStatus::PendingApproval);
            $period->transitionTo(WorkerEarningPeriodStatus::Approved, [
                'approved_at' => now(),
            ]);

            WorkerPayoutRecord::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_profile_id' => $engagement->worker_profile_id,
                'worker_engagement_id' => $engagement->id,
                'worker_earning_period_id' => $period->id,
                'amount_minor' => 10000,
                'currency' => 'SAR',
                'status' => WorkerPayoutStatus::Pending,
                'requested_at' => now(),
            ]);
        });

        return $period;
    }

    private function companyRequest(User $owner, Workspace $workspace): static
    {
        return $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
