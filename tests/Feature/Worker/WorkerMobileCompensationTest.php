<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerDisputeStatus;
use App\Enums\WorkerEarningLineType;
use App\Enums\WorkerEarningPeriodStatus;
use App\Enums\WorkerEarningSourceType;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkerPayoutStatus;
use App\Models\User;
use App\Models\Worker\WorkerDispute;
use App\Models\Worker\WorkerEarningLine;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerPayoutRecord;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerMobileCompensationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_engagement_earnings_exclude_drafts_and_expose_lines(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $draft = $this->createPeriod(
            $workspace,
            $engagement,
            $owner,
            WorkerEarningPeriodStatus::Draft,
            [
                [
                    'line_type' => WorkerEarningLineType::Base,
                    'source_type' => WorkerEarningSourceType::EngagementTerms,
                    'amount_minor' => 99999,
                    'currency' => 'SAR',
                    'description' => 'Unpublished draft line',
                ],
            ],
        );
        $period = $this->createPeriod(
            $workspace,
            $engagement,
            $owner,
            WorkerEarningPeriodStatus::PendingApproval,
            [
                [
                    'line_type' => WorkerEarningLineType::Base,
                    'source_type' => WorkerEarningSourceType::EngagementTerms,
                    'amount_minor' => 12500,
                    'currency' => 'SAR',
                    'description' => 'Base pay',
                ],
                [
                    'line_type' => WorkerEarningLineType::Deduction,
                    'source_type' => WorkerEarningSourceType::Adjustment,
                    'amount_minor' => -500,
                    'currency' => 'SAR',
                    'description' => 'Fee',
                ],
            ],
            '2026-08-01 00:00:00',
            '2026-08-31 23:59:59',
        );

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.earnings', $engagement))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $period->id)
            ->assertJsonPath('data.0.worker_engagement_id', $engagement->id)
            ->assertJsonPath('data.0.status', WorkerEarningPeriodStatus::PendingApproval->value)
            ->assertJsonPath('data.0.starts_at', '2026-08-01')
            ->assertJsonPath('data.0.ends_at', '2026-08-31')
            ->assertJsonPath('data.0.totals_minor.SAR', 12000)
            ->assertJsonPath('data.0.lines.0.line_type', 'base')
            ->assertJsonPath('data.0.lines.0.source_type', 'engagement_terms')
            ->assertJsonPath('data.0.lines.0.source_id', null)
            ->assertJsonPath('data.0.lines.0.amount_minor', 12500)
            ->assertJsonPath('data.0.lines.0.currency', 'SAR')
            ->assertJsonPath('data.0.lines.0.description', 'Base pay')
            ->assertJsonPath('data.0.lines.1.line_type', 'deduction')
            ->assertJsonPath('data.0.lines.1.amount_minor', -500)
            ->assertJsonMissingPath('data.0.workspace_id')
            ->assertJsonMissingPath('data.0.created_by_user_id')
            ->assertJsonMissingPath('data.0.approved_by_user_id')
            ->assertJsonMissingPath('data.0.approvals')
            ->assertJsonMissingPath('data.0.lines.0.created_by_user_id')
            ->assertJsonMissingPath('data.0.lines.0.adjustment_reason')
            ->assertJsonMissing(['data' => [['id' => $draft->id]]]);
    }

    public function test_foreign_worker_earnings_return_404(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [$other] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $this->createPeriod($workspace, $engagement, $owner, WorkerEarningPeriodStatus::Approved);

        Sanctum::actingAs($other);

        $this->getJson(route('mobile.v1.worker.engagements.earnings', $engagement))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_owned_payouts_are_visible_and_hide_platform_admin_fields(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [$other] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $period = $this->createPeriod($workspace, $engagement, $owner, WorkerEarningPeriodStatus::Approved);
        $payouts = $this->createPayoutsForStatuses($workspace, $engagement, $profile, $period);

        Sanctum::actingAs($worker);

        $response = $this->getJson(route('mobile.v1.worker.payouts.index'))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonMissingPath('data.0.created_by_platform_admin_id')
            ->assertJsonMissingPath('data.0.workspace_id')
            ->assertJsonMissingPath('data.0.provider_reference')
            ->assertJsonMissingPath('data.0.worker_profile_id');

        $statuses = collect($response->json('data'))->pluck('status')->sort()->values()->all();
        $this->assertSame([
            WorkerPayoutStatus::Cancelled->value,
            WorkerPayoutStatus::Failed->value,
            WorkerPayoutStatus::Paid->value,
            WorkerPayoutStatus::Pending->value,
            WorkerPayoutStatus::Processing->value,
        ], $statuses);

        $paid = collect($response->json('data'))->firstWhere('status', WorkerPayoutStatus::Paid->value);
        $this->assertSame($engagement->id, $paid['worker_engagement_id']);
        $this->assertSame($period->id, $paid['worker_earning_period_id']);
        $this->assertSame(12500, $paid['amount_minor']);
        $this->assertSame('SAR', $paid['currency']);
        $this->assertNotNull($paid['requested_at']);
        $this->assertNotNull($paid['processing_at']);
        $this->assertNotNull($paid['paid_at']);

        $failed = collect($response->json('data'))->firstWhere('status', WorkerPayoutStatus::Failed->value);
        $this->assertSame('Provider declined.', $failed['failure_reason']);
        $this->assertNotNull($failed['failed_at']);

        Sanctum::actingAs($other);
        $this->getJson(route('mobile.v1.worker.payouts.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['data' => [['id' => $payouts[0]->id]]]);
    }

    public function test_dispute_list_paginates_and_filters_owned_records(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [$other, $otherProfile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $otherEngagement = $this->createEngagement($workspace, $owner, $otherProfile);
        $open = $this->createDispute($workspace, $engagement, $worker, WorkerDisputeStatus::Open, '2026-09-02 10:00:00');
        $resolved = $this->createDispute(
            $workspace,
            $engagement,
            $worker,
            WorkerDisputeStatus::Resolved,
            '2026-09-20 10:00:00',
        );
        $this->createDispute($workspace, $otherEngagement, $other, WorkerDisputeStatus::Open, '2026-09-03 10:00:00');

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.disputes.index', [
            'per_page' => 1,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson(route('mobile.v1.worker.disputes.index', [
            'status' => WorkerDisputeStatus::Open->value,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonPath('data.0.status', WorkerDisputeStatus::Open->value)
            ->assertJsonMissingPath('data.0.review_notes')
            ->assertJsonMissingPath('data.0.reported_by_user_id')
            ->assertJsonMissingPath('data.0.reviewer_platform_admin_id');

        $this->getJson(route('mobile.v1.worker.disputes.index', [
            'from' => '2026-09-15',
            'to' => '2026-09-25',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $resolved->id);
    }

    public function test_worker_can_show_and_create_owned_disputes_but_cannot_resolve(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [$other] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $dispute = $this->createDispute(
            $workspace,
            $engagement,
            $worker,
            WorkerDisputeStatus::Open,
            '2026-09-04 09:00:00',
            'Internal review note',
        );

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.disputes.show', $dispute))
            ->assertOk()
            ->assertJsonPath('data.id', $dispute->id)
            ->assertJsonPath('data.worker_engagement_id', $engagement->id)
            ->assertJsonPath('data.reporter_type', 'worker')
            ->assertJsonPath('data.category', 'schedule')
            ->assertJsonPath('data.description', 'Visible dispute')
            ->assertJsonPath('data.under_review_at', null)
            ->assertJsonMissingPath('data.review_notes')
            ->assertJsonMissingPath('data.reported_by_user_id')
            ->assertJsonMissingPath('data.reviewer_platform_admin_id');

        $this->postJson(route('mobile.v1.worker.engagements.disputes.store', $engagement), [
            'category' => 'access',
            'description' => 'The granted access does not match the offer.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'access')
            ->assertJsonPath('data.status', WorkerDisputeStatus::Open->value)
            ->assertJsonPath('data.worker_engagement_id', $engagement->id)
            ->assertJsonMissingPath('data.review_notes');

        $this->assertDatabaseCount('worker_disputes', 2);

        $this->patchJson(route('mobile.v1.worker.disputes.show', $dispute), [
            'status' => WorkerDisputeStatus::Resolved->value,
            'resolution_summary' => 'Should not work',
        ])->assertMethodNotAllowed();

        $this->postJson('/api/mobile/v1/worker/disputes/'.$dispute->id.'/resolve', [
            'resolution_summary' => 'Should not work',
        ])->assertNotFound();

        $this->assertDatabaseHas('worker_disputes', [
            'id' => $dispute->id,
            'status' => WorkerDisputeStatus::Open->value,
        ]);

        Sanctum::actingAs($other);
        $this->getJson(route('mobile.v1.worker.disputes.show', $dispute))
            ->assertNotFound()
            ->assertJsonPath('success', false);
        $this->postJson(route('mobile.v1.worker.engagements.disputes.store', $engagement), [
            'category' => 'access',
            'description' => 'Foreign worker must not open this case.',
        ])->assertNotFound();
    }

    public function test_worker_can_dispute_pending_and_approved_periods_without_creating_a_case(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $pending = $this->createPeriod($workspace, $engagement, $owner, WorkerEarningPeriodStatus::PendingApproval);
        $approved = $this->createPeriod(
            $workspace,
            $engagement,
            $owner,
            WorkerEarningPeriodStatus::Approved,
            startsAt: '2026-08-01 00:00:00',
            endsAt: '2026-08-31 23:59:59',
        );

        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.earnings.dispute', [$engagement, $pending]), [
            'reason' => 'The pending amount needs review.',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $pending->id)
            ->assertJsonPath('data.status', WorkerEarningPeriodStatus::Disputed->value);

        $this->postJson(route('mobile.v1.worker.engagements.earnings.dispute', [$engagement, $approved]), [
            'reason' => 'The approved amount needs review.',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $approved->id)
            ->assertJsonPath('data.status', WorkerEarningPeriodStatus::Disputed->value);

        $this->assertSame(
            WorkerEarningPeriodStatus::Disputed,
            WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($pending->id)->status,
        );
        $this->assertSame(
            WorkerEarningPeriodStatus::Disputed,
            WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($approved->id)->status,
        );
        $this->assertDatabaseCount('worker_disputes', 0);
    }

    public function test_worker_cannot_dispute_paid_period_or_period_with_open_payout(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $paid = $this->createPeriod($workspace, $engagement, $owner, WorkerEarningPeriodStatus::Paid);
        $approved = $this->createPeriod(
            $workspace,
            $engagement,
            $owner,
            WorkerEarningPeriodStatus::Approved,
            startsAt: '2026-08-01 00:00:00',
            endsAt: '2026-08-31 23:59:59',
        );
        $this->createPayout($workspace, $engagement, $profile, $approved, WorkerPayoutStatus::Pending);

        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.earnings.dispute', [$engagement, $paid]), [
            'reason' => 'Too late.',
        ])->assertUnprocessable();

        $this->postJson(route('mobile.v1.worker.engagements.earnings.dispute', [$engagement, $approved]), [
            'reason' => 'Open payout should block this.',
        ])->assertUnprocessable();

        $this->assertSame(
            WorkerEarningPeriodStatus::Paid,
            WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($paid->id)->status,
        );
        $this->assertSame(
            WorkerEarningPeriodStatus::Approved,
            WorkerEarningPeriod::withoutGlobalScopes()->findOrFail($approved->id)->status,
        );
        $this->assertDatabaseCount('worker_disputes', 0);
    }

    public function test_compensation_routes_use_worker_ownership_not_workspace_membership(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $this->createPeriod($workspace, $engagement, $owner, WorkerEarningPeriodStatus::Approved);

        $this->assertFalse($worker->workspaces()->where('workspaces.id', $workspace->id)->exists());

        $this->getJson(route('mobile.v1.worker.engagements.earnings', $engagement))
            ->assertUnauthorized();

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.earnings', $engagement))
            ->assertOk();
        $this->getJson(route('mobile.v1.worker.payouts.index'))
            ->assertOk();
        $this->getJson(route('mobile.v1.worker.disputes.index'))
            ->assertOk();

    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createVerifiedWorker(): array
    {
        $worker = User::factory()->create();
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => $worker->name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
        ]);

        return [$worker, $profile];
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
            'name' => 'Compensation Company',
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }

    private function createEngagement(
        Workspace $workspace,
        User $owner,
        WorkerProfile $profile,
    ): WorkerEngagement {
        return WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active,
            'job_title' => 'Independent Worker',
            'created_by' => $owner->id,
            'active_at' => now(),
        ]);
    }

    /**
     * @param  list<array{
     *     line_type: WorkerEarningLineType,
     *     source_type: WorkerEarningSourceType,
     *     amount_minor: int,
     *     currency: string,
     *     description?: string|null
     * }>  $lines
     */
    private function createPeriod(
        Workspace $workspace,
        WorkerEngagement $engagement,
        User $owner,
        WorkerEarningPeriodStatus $status,
        array $lines = [],
        string $startsAt = '2026-09-01 00:00:00',
        string $endsAt = '2026-09-30 23:59:59',
    ): WorkerEarningPeriod {
        if ($lines === []) {
            $lines = [[
                'line_type' => WorkerEarningLineType::Base,
                'source_type' => WorkerEarningSourceType::EngagementTerms,
                'amount_minor' => 12500,
                'currency' => 'SAR',
                'description' => 'Base pay',
            ]];
        }

        return $this->inWorkspace($workspace, function () use (
            $engagement,
            $owner,
            $status,
            $lines,
            $startsAt,
            $endsAt,
        ): WorkerEarningPeriod {
            $period = WorkerEarningPeriod::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'status' => WorkerEarningPeriodStatus::Draft,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'created_by_user_id' => $owner->id,
            ]);

            foreach ($lines as $line) {
                WorkerEarningLine::query()->create([
                    'workspace_id' => $engagement->workspace_id,
                    'worker_engagement_id' => $engagement->id,
                    'worker_earning_period_id' => $period->id,
                    'line_type' => $line['line_type'],
                    'source_type' => $line['source_type'],
                    'amount_minor' => $line['amount_minor'],
                    'currency' => $line['currency'],
                    'description' => $line['description'] ?? null,
                    'created_by_user_id' => $owner->id,
                ]);
            }

            if ($status !== WorkerEarningPeriodStatus::Draft) {
                $period->transitionTo(WorkerEarningPeriodStatus::PendingApproval);
            }

            if (in_array($status, [
                WorkerEarningPeriodStatus::Approved,
                WorkerEarningPeriodStatus::Disputed,
                WorkerEarningPeriodStatus::Paid,
            ], true)) {
                $period->transitionTo(WorkerEarningPeriodStatus::Approved, [
                    'approved_by_user_id' => $owner->id,
                    'approved_at' => now(),
                ]);
            }

            if ($status === WorkerEarningPeriodStatus::Disputed) {
                $period->transitionTo(WorkerEarningPeriodStatus::Disputed);
            }

            if ($status === WorkerEarningPeriodStatus::Paid) {
                $period->transitionTo(WorkerEarningPeriodStatus::Paid, [
                    'paid_at' => now(),
                ]);
            }

            return $period->refresh();
        });
    }

    /**
     * @return list<WorkerPayoutRecord>
     */
    private function createPayoutsForStatuses(
        Workspace $workspace,
        WorkerEngagement $engagement,
        WorkerProfile $profile,
        WorkerEarningPeriod $period,
    ): array {
        $pending = $this->createPayout($workspace, $engagement, $profile, $period, WorkerPayoutStatus::Pending);
        $processing = $this->createPayout($workspace, $engagement, $profile, $period, WorkerPayoutStatus::Processing);
        $paid = $this->createPayout($workspace, $engagement, $profile, $period, WorkerPayoutStatus::Paid);
        $failed = $this->createPayout($workspace, $engagement, $profile, $period, WorkerPayoutStatus::Failed);
        $cancelled = $this->createPayout($workspace, $engagement, $profile, $period, WorkerPayoutStatus::Cancelled);

        return [$pending, $processing, $paid, $failed, $cancelled];
    }

    private function createPayout(
        Workspace $workspace,
        WorkerEngagement $engagement,
        WorkerProfile $profile,
        WorkerEarningPeriod $period,
        WorkerPayoutStatus $status,
    ): WorkerPayoutRecord {
        return $this->inWorkspace($workspace, function () use (
            $engagement,
            $profile,
            $period,
            $status,
        ): WorkerPayoutRecord {
            $payout = WorkerPayoutRecord::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_profile_id' => $profile->id,
                'worker_engagement_id' => $engagement->id,
                'worker_earning_period_id' => $period->id,
                'amount_minor' => 12500,
                'currency' => 'SAR',
                'status' => WorkerPayoutStatus::Pending,
                'requested_at' => now(),
            ]);

            if ($status === WorkerPayoutStatus::Pending) {
                return $payout;
            }

            if ($status === WorkerPayoutStatus::Cancelled) {
                $payout->transitionTo(WorkerPayoutStatus::Cancelled, [
                    'cancelled_at' => now(),
                ]);

                return $payout->refresh();
            }

            $payout->transitionTo(WorkerPayoutStatus::Processing, [
                'processing_at' => now(),
            ]);

            if ($status === WorkerPayoutStatus::Processing) {
                return $payout->refresh();
            }

            if ($status === WorkerPayoutStatus::Paid) {
                $payout->transitionTo(WorkerPayoutStatus::Paid, [
                    'provider_reference' => 'ref-paid',
                    'paid_at' => now(),
                ]);
            }

            if ($status === WorkerPayoutStatus::Failed) {
                $payout->transitionTo(WorkerPayoutStatus::Failed, [
                    'failure_reason' => 'Provider declined.',
                    'failed_at' => now(),
                ]);
            }

            return $payout->refresh();
        });
    }

    private function createDispute(
        Workspace $workspace,
        WorkerEngagement $engagement,
        User $worker,
        WorkerDisputeStatus $status,
        string $openedAt,
        ?string $reviewNotes = null,
    ): WorkerDispute {
        return $this->inWorkspace($workspace, function () use (
            $engagement,
            $worker,
            $status,
            $openedAt,
            $reviewNotes,
        ): WorkerDispute {
            return WorkerDispute::query()->create([
                'workspace_id' => $engagement->workspace_id,
                'worker_engagement_id' => $engagement->id,
                'reporter_type' => 'worker',
                'reported_by_user_id' => $worker->id,
                'category' => 'schedule',
                'description' => 'Visible dispute',
                'status' => $status,
                'review_notes' => $reviewNotes,
                'resolution_summary' => $status === WorkerDisputeStatus::Resolved ? 'Handled' : null,
                'opened_at' => $openedAt,
                'resolved_at' => $status === WorkerDisputeStatus::Resolved ? $openedAt : null,
            ]);
        });
    }
}
