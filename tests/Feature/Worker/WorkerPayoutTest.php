<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEarningPeriodStatus;
use App\Enums\WorkerPayoutStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerPayoutRecord;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerPayoutTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_platform_creates_a_provider_neutral_payout_from_an_approved_period(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $period = $this->createApprovedPeriod($owner, $workspace, $engagement);
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-payouts.store'), [
                'period_id' => $period->id,
                'amount_minor' => 1,
            ])
            ->assertRedirect();

        $payout = WorkerPayoutRecord::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(WorkerPayoutStatus::Pending, $payout->status);
        $this->assertSame(12500, $payout->amount_minor);
        $this->assertSame('SAR', $payout->currency);
        $this->assertSame($profile->id, $payout->worker_profile_id);
        $this->assertNull($payout->provider_reference);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.payout.created',
            'entity_id' => $payout->id,
        ]);
    }

    public function test_payout_transitions_are_controlled_and_mark_the_period_paid(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $period = $this->createApprovedPeriod($owner, $workspace, $engagement);
        $admin = PlatformAdmin::factory()->create();
        $this->createPayout($admin, $period);
        $payout = WorkerPayoutRecord::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($admin, 'platform_admin')
            ->patch(route('platform.worker-payouts.update', $payout), [
                'status' => WorkerPayoutStatus::Processing->value,
            ])
            ->assertRedirect();

        $this->actingAs($admin, 'platform_admin')
            ->patch(route('platform.worker-payouts.update', $payout), [
                'status' => WorkerPayoutStatus::Paid->value,
                'provider_reference' => 'provider-neutral-reference-1',
            ])
            ->assertRedirect();

        $payout = WorkerPayoutRecord::withoutGlobalScopes()->findOrFail($payout->id);
        $period = WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($period->id);
        $this->assertSame(WorkerPayoutStatus::Paid, $payout->status);
        $this->assertSame(WorkerEarningPeriodStatus::Paid, $period->status);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.payout.status_changed',
            'entity_id' => $payout->id,
        ]);

        $this->actingAs($admin, 'platform_admin')
            ->patch(route('platform.worker-payouts.update', $payout), [
                'status' => WorkerPayoutStatus::Failed->value,
                'reason' => 'attempted rewrite',
            ])
            ->assertSessionHasErrors('payout');

        $this->assertSame(
            WorkerPayoutStatus::Paid,
            WorkerPayoutRecord::withoutGlobalScopes()->findOrFail($payout->id)->status,
        );
    }

    public function test_draft_period_cannot_create_a_payout(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $period = $this->createPeriod($owner, $workspace, $engagement);
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-payouts.store'), ['period_id' => $period->id])
            ->assertSessionHasErrors('payout');

        $this->assertDatabaseCount('worker_payout_records', 0);
    }

    public function test_worker_and_company_views_keep_payouts_engagement_scoped(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$workerA, $profileA] = $this->createVerifiedWorker();
        [$workerB, $profileB] = $this->createVerifiedWorker();
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $workerA, $profileA);
        $engagementB = $this->createActiveEngagement($ownerB, $workspaceB, $workerB, $profileB);
        $periodA = $this->createApprovedPeriod($ownerA, $workspaceA, $engagementA);
        $admin = PlatformAdmin::factory()->create();
        $this->createPayout($admin, $periodA);

        $this->actingAs($workerA)
            ->get(route('worker.engagements.earnings.show', $engagementA->id))
            ->assertOk()
            ->assertSee('125.00');

        $this->actingAs($workerB)
            ->get(route('worker.engagements.earnings.show', $engagementA->id))
            ->assertNotFound();

        $this->companyRequest($ownerA, $workspaceA)
            ->get(route('workspace.worker-engagements.earnings.index', $engagementA))
            ->assertOk()
            ->assertSee('قيد الانتظار');

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-engagements.earnings.index', $engagementA))
            ->assertNotFound();

        $this->assertSame($workspaceB->id, $engagementB->workspace_id);
    }

    public function test_paid_status_requires_a_provider_neutral_reference_and_failures_require_a_reason(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $period = $this->createApprovedPeriod($owner, $workspace, $engagement);
        $admin = PlatformAdmin::factory()->create();
        $this->createPayout($admin, $period);
        $payout = WorkerPayoutRecord::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($admin, 'platform_admin')
            ->patch(route('platform.worker-payouts.update', $payout), [
                'status' => WorkerPayoutStatus::Paid->value,
            ])
            ->assertSessionHasErrors('provider_reference');

        $this->actingAs($admin, 'platform_admin')
            ->patch(route('platform.worker-payouts.update', $payout), [
                'status' => WorkerPayoutStatus::Processing->value,
            ])
            ->assertRedirect();

        $this->actingAs($admin, 'platform_admin')
            ->patch(route('platform.worker-payouts.update', $payout), [
                'status' => WorkerPayoutStatus::Failed->value,
            ])
            ->assertSessionHasErrors('reason');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Payout Workspace'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
            'name' => $name,
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
    private function createVerifiedWorker(): array
    {
        $user = User::factory()->create();
        $profile = WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $user->name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent sales specialist.',
            'sales_experience_level' => 'senior',
        ]);

        return [$user, $profile];
    }

    private function createActiveEngagement(
        User $owner,
        Workspace $workspace,
        User $worker,
        WorkerProfile $profile,
    ): WorkerEngagement {
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'job_title' => 'Independent Worker',
            ])
            ->assertRedirect();
        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->latest('id')
            ->firstOrFail();
        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $engagement->id))
            ->assertRedirect();
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.activate', $engagement))
            ->assertRedirect();

        return WorkerEngagement::withoutGlobalScopes()->findOrFail($engagement->id);
    }

    private function createPeriod(
        User $owner,
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): WorkerEarningPeriod {
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.store', $engagement), [
                'starts_at' => '2026-09-01 00:00:00',
                'ends_at' => '2026-09-30 23:59:59',
            ])
            ->assertRedirect();

        return WorkerEarningPeriod::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function createApprovedPeriod(
        User $owner,
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): WorkerEarningPeriod {
        $period = $this->createPeriod($owner, $workspace, $engagement);
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.lines.store', [
                $engagement,
                $period->id,
            ]), [
                'line_type' => 'base',
                'source_type' => 'engagement_terms',
                'amount_minor' => '12500',
                'currency' => 'SAR',
            ])
            ->assertRedirect();
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.submit', [
                $engagement,
                $period->id,
            ]))
            ->assertRedirect();
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.approve', [
                $engagement,
                $period->id,
            ]))
            ->assertRedirect();

        return WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($period->id);
    }

    private function createPayout(
        PlatformAdmin $admin,
        WorkerEarningPeriod $period,
    ): WorkerPayoutRecord {
        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-payouts.store'), [
                'period_id' => $period->id,
            ])
            ->assertRedirect();

        return WorkerPayoutRecord::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
