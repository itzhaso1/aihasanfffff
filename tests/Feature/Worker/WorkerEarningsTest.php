<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEarningPeriodStatus;
use App\Models\User;
use App\Models\Worker\WorkerEarningLine;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Worker\WorkerEarningsException;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerEarningsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_company_can_create_submit_and_approve_engagement_scoped_earning_period(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.store', $engagement), [
                'starts_at' => '2026-09-01 00:00:00',
                'ends_at' => '2026-09-30 23:59:59',
            ])
            ->assertRedirect();

        $period = WorkerEarningPeriod::withoutGlobalScopes()->firstOrFail();

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
            ]), [
                'notes' => 'Reviewed against the engagement terms.',
            ])
            ->assertRedirect();

        $period = WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($period->id);
        $this->assertSame(WorkerEarningPeriodStatus::Approved, $period->status);
        $this->assertSame($engagement->id, $period->worker_engagement_id);
        $this->assertSame($workspace->id, $period->workspace_id);
        $this->assertDatabaseHas('worker_earning_approvals', [
            'worker_earning_period_id' => $period->id,
            'approved_by_user_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.earning_period.approved',
            'entity_id' => $period->id,
        ]);
    }

    public function test_inactive_engagement_cannot_receive_an_earning_period(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createAcceptedEngagement($owner, $workspace, $worker, $profile);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.store', $engagement), [
                'starts_at' => '2026-09-01 00:00:00',
                'ends_at' => '2026-09-30 23:59:59',
            ])
            ->assertSessionHasErrors('earnings');

        $this->assertDatabaseCount('worker_earning_periods', 0);
    }

    public function test_period_boundaries_and_minor_amounts_are_validated_without_float_money(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.store', $engagement), [
                'starts_at' => '2026-09-30 00:00:00',
                'ends_at' => '2026-09-01 00:00:00',
            ])
            ->assertSessionHasErrors('ends_at');

        $this->createPeriod($owner, $workspace, $engagement);
        $period = WorkerEarningPeriod::withoutGlobalScopes()->firstOrFail();

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.lines.store', [
                $engagement,
                $period->id,
            ]), [
                'line_type' => 'base',
                'source_type' => 'engagement_terms',
                'amount_minor' => '125.50',
                'currency' => 'SAR',
            ])
            ->assertSessionHasErrors('amount_minor');

        $this->assertDatabaseCount('worker_earning_lines', 0);
    }

    public function test_worker_and_company_cannot_cross_engagement_or_workspace_boundaries(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$workerA, $profileA] = $this->createVerifiedWorker();
        [$workerB, $profileB] = $this->createVerifiedWorker();
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $workerA, $profileA);
        $engagementB = $this->createActiveEngagement($ownerB, $workspaceB, $workerB, $profileB);
        $period = $this->createPeriod($ownerA, $workspaceA, $engagementA);

        $this->actingAs($workerB)
            ->get(route('worker.engagements.earnings.show', $engagementA->id))
            ->assertNotFound();

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-engagements.earnings.index', $engagementA))
            ->assertNotFound();

        $this->assertSame($engagementB->workspace_id, $workspaceB->id);
        $this->assertSame($workspaceA->id, $period->workspace_id);
    }

    public function test_one_worker_cannot_mix_earnings_between_two_company_engagements(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $worker, $profile);
        $engagementB = $this->createActiveEngagement($ownerB, $workspaceB, $worker, $profile);
        $periodA = $this->createApprovedPeriod($ownerA, $workspaceA, $worker, $profile, $engagementA);

        $this->actingAs($worker)
            ->get(route('worker.engagements.earnings.show', $engagementB->id))
            ->assertOk()
            ->assertSee('لا توجد فترات أرباح.');

        $this->actingAs($worker)
            ->post(route('worker.engagements.earnings.dispute', [
                $engagementB->id,
                $periodA->id,
            ]))
            ->assertNotFound();

        $this->assertSame(
            WorkerEarningPeriodStatus::Approved,
            WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($periodA->id)->status,
        );
    }

    public function test_worker_can_view_and_dispute_only_their_own_earning_period(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $period = $this->createApprovedPeriod($owner, $workspace, $worker, $profile, $engagement);

        $this->actingAs($worker)
            ->get(route('worker.engagements.earnings.show', $engagement->id))
            ->assertOk()
            ->assertSee((string) $period->id);

        $this->actingAs($worker)
            ->post(route('worker.engagements.earnings.dispute', [
                $engagement->id,
                $period->id,
            ]), [
                'reason' => 'The approved amount needs review.',
            ])
            ->assertRedirect();

        $period = WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($period->id);
        $this->assertSame(WorkerEarningPeriodStatus::Disputed, $period->status);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.earning_period.disputed',
            'entity_id' => $period->id,
        ]);
    }

    public function test_finalized_earning_lines_cannot_be_edited(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $period = $this->createApprovedPeriod($owner, $workspace, $worker, $profile, $engagement);
        $line = WorkerEarningLine::withoutGlobalScopes()->firstOrFail();

        $this->expectException(WorkerEarningsException::class);
        app(WorkspaceContext::class)->runFor($workspace, function () use ($line): void {
            $line->update(['description' => 'attempted rewrite']);
        });
    }

    public function test_mixed_currency_period_cannot_be_approved(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $period = $this->createPeriod($owner, $workspace, $engagement);

        $this->addLine($owner, $workspace, $engagement, $period, [
            'line_type' => 'base',
            'source_type' => 'engagement_terms',
            'amount_minor' => '1000',
            'currency' => 'SAR',
        ]);
        $this->addLine($owner, $workspace, $engagement, $period, [
            'line_type' => 'bonus',
            'source_type' => 'engagement_terms',
            'amount_minor' => '1000',
            'currency' => 'JOD',
        ]);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.submit', [
                $engagement,
                $period->id,
            ]));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.approve', [
                $engagement,
                $period->id,
            ]))
            ->assertSessionHasErrors('earnings');

        $this->assertSame(
            WorkerEarningPeriodStatus::PendingApproval,
            WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($period->id)->status,
        );
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Earnings Workspace'): array
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

    private function createAcceptedEngagement(
        User $owner,
        Workspace $workspace,
        User $worker,
        WorkerProfile $profile,
    ): WorkerEngagement {
        $engagement = $this->createOffer($owner, $workspace, $profile);
        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $engagement->id))
            ->assertRedirect();

        return WorkerEngagement::withoutGlobalScopes()->findOrFail($engagement->id);
    }

    private function createActiveEngagement(
        User $owner,
        Workspace $workspace,
        User $worker,
        WorkerProfile $profile,
    ): WorkerEngagement {
        $engagement = $this->createAcceptedEngagement($owner, $workspace, $worker, $profile);
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.activate', $engagement))
            ->assertRedirect();

        return WorkerEngagement::withoutGlobalScopes()->findOrFail($engagement->id);
    }

    private function createOffer(
        User $owner,
        Workspace $workspace,
        WorkerProfile $profile,
    ): WorkerEngagement {
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'job_title' => 'Independent Worker',
            ])
            ->assertRedirect();

        return WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->latest('id')
            ->firstOrFail();
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
        User $worker,
        WorkerProfile $profile,
        WorkerEngagement $engagement,
    ): WorkerEarningPeriod {
        $period = $this->createPeriod($owner, $workspace, $engagement);
        $this->addLine($owner, $workspace, $engagement, $period, [
            'line_type' => 'base',
            'source_type' => 'engagement_terms',
            'amount_minor' => '12500',
            'currency' => 'SAR',
        ]);
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

    /**
     * @param  array<string, string>  $attributes
     */
    private function addLine(
        User $owner,
        Workspace $workspace,
        WorkerEngagement $engagement,
        WorkerEarningPeriod $period,
        array $attributes,
    ): void {
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.earning-periods.lines.store', [
                $engagement,
                $period->id,
            ]), $attributes)
            ->assertRedirect();
    }

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
