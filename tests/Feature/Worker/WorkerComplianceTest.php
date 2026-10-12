<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerDisputeStatus;
use App\Enums\WorkerEarningPeriodStatus;
use App\Enums\WorkerEngagementStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerDispute;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Worker\WorkerDisputeException;
use App\Services\Worker\WorkerEngagementAuthorization;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkerComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_company_lifecycle_controls_are_audited_and_invalid_transition_is_rejected(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createWorker('Lifecycle Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.pause', $engagement))
            ->assertRedirect();
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.resume', $engagement))
            ->assertRedirect();
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.terminate', $engagement), [
                'reason' => 'The company ended the engagement.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Terminated->value,
            'termination_reason' => 'The company ended the engagement.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'entity_type' => WorkerEngagement::class,
            'entity_id' => $engagement->id,
            'action' => 'worker.engagement.transitioned',
        ]);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.resume', $engagement))
            ->assertSessionHasErrors('engagement');

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Terminated->value,
        ]);
    }

    public function test_paused_engagement_blocks_new_sessions_and_inbox_operations_but_preserves_history(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Paused Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->createAssignedConversation($workspace, $worker, $engagement);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect();
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.end', $engagement->id))
            ->assertRedirect();
        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [
                'workspace' => $workspace->id,
                'conversation' => $conversation->id,
            ]), ['content' => 'Historical worker reply'])
            ->assertRedirect();

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.pause', $engagement))
            ->assertRedirect();

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect()
            ->assertSessionHasErrors('session');
        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [
                'workspace' => $workspace->id,
                'conversation' => $conversation->id,
            ]), ['content' => 'Blocked while paused'])
            ->assertNotFound();

        $this->assertDatabaseCount('worker_work_sessions', 1);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Historical worker reply',
        ]);
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Blocked while paused',
        ]);
    }

    public function test_suspended_worker_loses_operational_access_without_terminating_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Suspended Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile, [
            'inbox.messages.read',
            'inbox.messages.send',
        ]);
        $conversation = $this->createAssignedConversation($workspace, $worker, $engagement);
        $platformAdmin = PlatformAdmin::factory()->create();

        $this->actingAs($worker)
            ->get(route('worker.engagements.sessions.show', $engagement->id))
            ->assertOk()
            ->assertSee('بدء جلسة العمل');

        $this->actingAs($platformAdmin, 'platform_admin')
            ->post(route('platform.worker-verifications.suspend', $profile->id), [
                'reason' => 'Platform review requires a temporary suspension.',
            ])
            ->assertRedirect();

        $this->actingAs($worker)
            ->get(route('worker.engagements.sessions.show', $engagement->id))
            ->assertOk()
            ->assertSee('لا يمكن بدء جلسة عمل لأن حساب العامل معلّق.')
            ->assertDontSee('بدء جلسة العمل');

        $this->assertDatabaseHas('worker_profiles', [
            'id' => $profile->id,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_SUSPENDED,
            'verification_status' => WorkerProfile::VERIFICATION_SUSPENDED,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Active->value,
        ]);

        $this->assertFalse($this->inWorkspace(
            $workspace,
            fn (): bool => app(WorkerEngagementAuthorization::class)->workerCan(
                $worker,
                $workspace,
                $engagement,
                'inbox.messages.read',
            ),
        ));
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect()
            ->assertSessionHasErrors('session');
        $this->actingAs($worker)
            ->post(route('worker.inbox.messages.store', [
                'workspace' => $workspace->id,
                'conversation' => $conversation->id,
            ]), ['content' => 'Blocked while suspended'])
            ->assertNotFound();

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => WorkerProfile::class,
            'entity_id' => $profile->id,
            'action' => 'worker.verification.suspended',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Initial message',
        ]);
    }

    public function test_suspended_worker_is_excluded_from_discovery_without_deleting_profile(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createWorker('Discoverable Worker');
        $profile->discoverySettings()->create(['is_discoverable' => true]);
        $platformAdmin = PlatformAdmin::factory()->create();

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index'))
            ->assertOk()
            ->assertSee($profile->display_name);

        $this->actingAs($platformAdmin, 'platform_admin')
            ->post(route('platform.worker-verifications.suspend', $profile->id), [
                'reason' => 'Suspended for compliance review.',
            ])
            ->assertRedirect();

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index'))
            ->assertOk()
            ->assertDontSee($profile->display_name);
        $this->assertModelExists($profile);
    }

    public function test_worker_and_company_can_open_only_authorized_engagement_disputes(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Company A');
        [$ownerB, $workspaceB] = $this->createCompany('Company B');
        [$worker, $profile] = $this->createWorker('Dispute Worker');
        [$otherWorker, $otherProfile] = $this->createWorker('Other Worker');
        $engagementA = $this->createEngagement($workspaceA, $ownerA, $profile);
        $engagementB = $this->createEngagement($workspaceB, $ownerB, $profile);
        $otherEngagement = $this->createEngagement($workspaceA, $ownerA, $otherProfile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.disputes.store', $engagementA->id), [
                'category' => 'schedule',
                'description' => 'The agreed schedule was changed without notice.',
            ])
            ->assertRedirect();

        $this->companyRequest($ownerB, $workspaceB)
            ->post(route('workspace.worker-engagements.disputes.store', $engagementB), [
                'category' => 'delivery',
                'description' => 'The company is recording a delivery concern.',
            ])
            ->assertRedirect();

        $this->actingAs($otherWorker)
            ->post(route('worker.engagements.disputes.store', $engagementA->id), [
                'category' => 'unauthorized',
                'description' => 'This must not be recorded for another worker.',
            ])
            ->assertNotFound();
        $this->companyRequest($ownerB, $workspaceB)
            ->post(route('workspace.worker-engagements.disputes.store', $otherEngagement), [
                'category' => 'cross-workspace',
                'description' => 'This must not be recorded for another workspace.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('worker_disputes', [
            'workspace_id' => $workspaceA->id,
            'worker_engagement_id' => $engagementA->id,
            'category' => 'schedule',
            'reporter_type' => 'worker',
            'reported_by_user_id' => $worker->id,
            'status' => WorkerDisputeStatus::Open->value,
        ]);
        $this->assertDatabaseHas('worker_disputes', [
            'workspace_id' => $workspaceB->id,
            'worker_engagement_id' => $engagementB->id,
            'category' => 'delivery',
            'reporter_type' => 'company',
            'reported_by_user_id' => $ownerB->id,
            'status' => WorkerDisputeStatus::Open->value,
        ]);
        $this->assertDatabaseCount('worker_disputes', 2);
    }

    public function test_platform_review_lifecycle_is_controlled_and_audited(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Review Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $this->actingAs($worker)
            ->post(route('worker.engagements.disputes.store', $engagement->id), [
                'category' => 'access',
                'description' => 'A review is required.',
            ])
            ->assertRedirect();
        $dispute = WorkerDispute::withoutGlobalScopes()->latest('id')->firstOrFail();
        $admin = PlatformAdmin::factory()->create(['name' => 'Review Admin']);

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-disputes.close', $dispute->id))
            ->assertRedirect()
            ->assertSessionHasErrors('dispute');
        $this->assertDatabaseHas('worker_disputes', [
            'id' => $dispute->id,
            'status' => WorkerDisputeStatus::Open->value,
        ]);

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-disputes.under-review', $dispute->id), [
                'review_notes' => 'Internal review note.',
            ])
            ->assertRedirect();
        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-disputes.resolve', $dispute->id), [
                'resolution_summary' => 'The parties were informed of the outcome.',
                'review_notes' => 'Internal resolution note.',
            ])
            ->assertRedirect();
        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-disputes.close', $dispute->id), [
                'review_notes' => 'Closed after resolution.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_disputes', [
            'id' => $dispute->id,
            'status' => WorkerDisputeStatus::Closed->value,
            'resolution_summary' => 'The parties were informed of the outcome.',
            'reviewer_platform_admin_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => WorkerDispute::class,
            'entity_id' => $dispute->id,
            'action' => 'worker.dispute.opened',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => WorkerDispute::class,
            'entity_id' => $dispute->id,
            'action' => 'worker.dispute.transitioned',
        ]);
    }

    public function test_platform_review_notes_are_not_visible_to_company_or_worker(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Private Notes Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $this->actingAs($worker)
            ->post(route('worker.engagements.disputes.store', $engagement->id), [
                'category' => 'review',
                'description' => 'A public dispute description.',
            ])
            ->assertRedirect();
        $dispute = WorkerDispute::withoutGlobalScopes()->latest('id')->firstOrFail();
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-disputes.under-review', $dispute->id), [
                'review_notes' => 'Private platform review note.',
            ])
            ->assertRedirect();

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-disputes.show', $dispute->id))
            ->assertOk()
            ->assertSee('A public dispute description.')
            ->assertDontSee('Private platform review note.');
        $this->actingAs($worker)
            ->get(route('worker.disputes.show', $dispute->id))
            ->assertOk()
            ->assertDontSee('Private platform review note.');
        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.worker-disputes.show', $dispute->id))
            ->assertOk()
            ->assertSee('Private platform review note.');
    }

    public function test_dispute_records_are_isolated_and_immutable(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Isolation A');
        [$ownerB, $workspaceB] = $this->createCompany('Isolation B');
        [$workerA, $profileA] = $this->createWorker('Isolation Worker A');
        [$workerB, $profileB] = $this->createWorker('Isolation Worker B');
        $engagementA = $this->createEngagement($workspaceA, $ownerA, $profileA);
        $engagementB = $this->createEngagement($workspaceB, $ownerB, $profileB);

        $this->actingAs($workerA)
            ->post(route('worker.engagements.disputes.store', $engagementA->id), [
                'category' => 'isolation',
                'description' => 'Workspace A dispute.',
            ])
            ->assertRedirect();
        $disputeA = WorkerDispute::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-disputes.show', $disputeA->id))
            ->assertNotFound();
        $this->actingAs($workerB)
            ->get(route('worker.disputes.show', $disputeA->id))
            ->assertNotFound();

        $this->expectException(WorkerDisputeException::class);
        $this->inWorkspace($workspaceA, function () use ($disputeA): void {
            $disputeA->description = 'Changed after opening.';
            $disputeA->save();
        });
    }

    public function test_dispute_filters_are_form_validated_and_paginated(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Filter Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $this->actingAs($worker)
            ->post(route('worker.engagements.disputes.store', $engagement->id), [
                'category' => 'filter',
                'description' => 'Filterable dispute.',
            ])
            ->assertRedirect();

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-disputes.index', [
                'per_page' => 100,
                'from' => '2026-01-01',
                'to' => '2026-02-15',
            ]))
            ->assertSessionHasErrors(['per_page', 'to']);
    }

    public function test_generic_dispute_does_not_change_finalized_earnings(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Earnings Protection Worker');
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $period = $this->inWorkspace($workspace, function () use ($owner, $engagement): WorkerEarningPeriod {
            $period = WorkerEarningPeriod::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'status' => WorkerEarningPeriodStatus::Draft,
                'starts_at' => '2026-09-01 00:00:00',
                'ends_at' => '2026-09-30 23:59:59',
                'created_by_user_id' => $owner->id,
            ]);
            $period->transitionTo(WorkerEarningPeriodStatus::PendingApproval);
            $period->transitionTo(WorkerEarningPeriodStatus::Approved, [
                'approved_by_user_id' => $owner->id,
                'approved_at' => now(),
            ]);

            return $period->refresh();
        });

        $this->actingAs($worker)
            ->post(route('worker.engagements.disputes.store', $engagement->id), [
                'category' => 'operations',
                'description' => 'This is not a financial hold request.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_earning_periods', [
            'id' => $period->id,
            'status' => WorkerEarningPeriodStatus::Approved->value,
        ]);
    }

    public function test_platform_dispute_routes_require_platform_guard(): void
    {
        [$owner] = $this->createCompany();

        $this->actingAs($owner)
            ->get(route('platform.worker-disputes.index'))
            ->assertRedirect(route('platform.login'));

        $this->assertDatabaseCount('worker_disputes', 0);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Compliance Workspace'): array
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
            'professional_summary' => 'Independent worker profile.',
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
        array $capabilities = [],
    ): WorkerEngagement {
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Independent Worker',
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

    private function createAssignedConversation(
        Workspace $workspace,
        User $worker,
        WorkerEngagement $engagement,
    ): Conversation {
        $customer = $this->inWorkspace($workspace, function () use ($workspace): Customer {
            return Customer::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Compliance Customer',
                'email' => 'customer-'.$workspace->id.'@example.test',
                'phone' => '0500000000',
            ]);
        });

        $conversation = $this->inWorkspace($workspace, function () use ($customer, $engagement, $workspace, $worker): Conversation {
            return Conversation::query()->create([
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => 'manual',
                'external_id' => 'compliance:'.$workspace->id.':'.$engagement->id,
                'status' => 'open',
                'ai_enabled' => false,
                'assigned_user_id' => $worker->id,
                'assigned_worker_engagement_id' => $engagement->id,
            ]);
        });

        $this->inWorkspace($workspace, function () use ($conversation, $customer): void {
            $conversation->messages()->create([
                'workspace_id' => $conversation->workspace_id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'Initial message',
                'ai_generated' => false,
            ]);
        });

        return $conversation;
    }

    private function companyRequest(User $owner, Workspace $workspace): static
    {
        return $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
