<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerWorkSessionSegmentType;
use App\Enums\WorkerWorkSessionStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Worker\WorkerWorkSessionSegment;
use App\Models\Workspace;
use App\Services\Worker\WorkerWorkSessionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerWorkSessionTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_verified_worker_can_start_one_server_timed_session_for_an_active_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $this->travelTo('2026-09-27 10:00:00');

        $response = $this->actingAs($worker)->post(
            route('worker.engagements.sessions.start', $engagement->id),
            [
                'started_at' => '2000-01-01 00:00:00',
                'duration_seconds' => 999999,
            ],
        );

        $response->assertRedirect(route(
            'worker.engagements.sessions.show',
            $engagement->id,
            absolute: false,
        ));
        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(WorkerWorkSessionStatus::Started, $session->status);
        $this->assertSame('2026-09-27 10:00:00', $session->started_at->format('Y-m-d H:i:s'));
        $this->assertSame($workspace->id, $session->workspace_id);
        $this->assertSame($engagement->id, $session->worker_engagement_id);
        $this->assertSame($worker->id, $session->started_by_user_id);
        $this->assertDatabaseHas('worker_work_session_segments', [
            'worker_work_session_id' => $session->id,
            'segment_type' => WorkerWorkSessionSegmentType::Active->value,
            'ended_at' => null,
        ]);
    }

    public function test_non_active_engagement_cannot_start_a_session(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createAcceptedEngagement($owner, $workspace, $worker, $profile);

        $response = $this->actingAs($worker)->post(
            route('worker.engagements.sessions.start', $engagement->id),
        );

        $response->assertSessionHasErrors('session');
        $this->assertDatabaseCount('worker_work_sessions', 0);
    }

    public function test_unverified_worker_cannot_start_a_session_for_an_active_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $profile->forceFill([
            'verification_status' => WorkerProfile::VERIFICATION_PENDING_REVIEW,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
        ])->save();

        $response = $this->actingAs($worker)->post(
            route('worker.engagements.sessions.start', $engagement->id),
        );

        $response->assertSessionHasErrors('session');
        $this->assertDatabaseCount('worker_work_sessions', 0);
    }

    public function test_another_worker_cannot_start_or_end_a_session_for_an_owned_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [$otherWorker] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->actingAs($otherWorker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertNotFound();

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect();

        $this->actingAs($otherWorker)
            ->post(route('worker.engagements.sessions.end', $engagement->id))
            ->assertNotFound();

        $this->assertDatabaseHas('worker_work_sessions', [
            'worker_engagement_id' => $engagement->id,
            'status' => WorkerWorkSessionStatus::Started->value,
        ]);
    }

    public function test_duplicate_active_session_is_rejected(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect();
        $response = $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id));

        $response->assertSessionHasErrors('session');
        $this->assertDatabaseCount('worker_work_sessions', 1);
    }

    public function test_worker_can_end_own_session_and_duration_uses_server_timestamps(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->travelTo('2026-09-27 10:00:00');
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect();
        $this->travelTo('2026-09-27 10:45:00');

        $response = $this->actingAs($worker)->post(
            route('worker.engagements.sessions.end', $engagement->id),
            [
                'ended_at' => '2099-01-01 00:00:00',
                'duration_seconds' => 1,
            ],
        );

        $response->assertRedirect();
        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(WorkerWorkSessionStatus::Ended, $session->status);
        $this->assertSame('2026-09-27 10:45:00', $session->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame(2700, $session->duration_seconds);
        $this->assertNull($session->active_session_key);
        $this->assertDatabaseHas('worker_work_session_segments', [
            'worker_work_session_id' => $session->id,
            'segment_type' => WorkerWorkSessionSegmentType::Active->value,
            'duration_seconds' => 2700,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.work_session.ended',
            'entity_id' => $session->id,
        ]);
    }

    public function test_already_ended_session_cannot_be_ended_again(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect();
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.end', $engagement->id))
            ->assertRedirect();

        $response = $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.end', $engagement->id));

        $response->assertSessionHasErrors('session');
        $this->assertDatabaseCount('worker_work_sessions', 1);
    }

    public function test_explicit_break_and_resume_create_server_timed_non_overlapping_segments(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->travelTo('2026-09-27 10:00:00');
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id));
        $this->travelTo('2026-09-27 10:15:00');
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.break', $engagement->id));
        $this->travelTo('2026-09-27 10:30:00');
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.resume', $engagement->id));
        $this->travelTo('2026-09-27 10:45:00');
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.end', $engagement->id));

        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();
        $segments = WorkerWorkSessionSegment::withoutGlobalScopes()
            ->where('worker_work_session_id', $session->id)
            ->orderBy('started_at')
            ->get();

        $this->assertCount(3, $segments);
        $this->assertSame(WorkerWorkSessionSegmentType::Active, $segments[0]->segment_type);
        $this->assertSame(WorkerWorkSessionSegmentType::Break, $segments[1]->segment_type);
        $this->assertSame(WorkerWorkSessionSegmentType::Active, $segments[2]->segment_type);
        $this->assertSame(900, $segments[0]->duration_seconds);
        $this->assertSame(900, $segments[1]->duration_seconds);
        $this->assertSame(900, $segments[2]->duration_seconds);
        $this->assertSame(2700, $session->duration_seconds);
        $this->assertSame(
            $segments[0]->ended_at->getTimestamp(),
            $segments[1]->started_at->getTimestamp(),
        );
        $this->assertSame(
            $segments[1]->ended_at->getTimestamp(),
            $segments[2]->started_at->getTimestamp(),
        );
    }

    public function test_session_page_explains_paused_completed_and_terminated_engagements(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$pausedWorker, $pausedProfile] = $this->createVerifiedWorker();
        [$completedWorker, $completedProfile] = $this->createVerifiedWorker();
        [$terminatedWorker, $terminatedProfile] = $this->createVerifiedWorker();
        $paused = $this->createActiveEngagement($owner, $workspace, $pausedWorker, $pausedProfile);
        $completed = $this->createActiveEngagement($owner, $workspace, $completedWorker, $completedProfile);
        $terminated = $this->createActiveEngagement($owner, $workspace, $terminatedWorker, $terminatedProfile);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.pause', $paused))
            ->assertRedirect();
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.complete', $completed))
            ->assertRedirect();
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.terminate', $terminated), [
                'reason' => 'Engagement ended.',
            ])
            ->assertRedirect();

        $this->actingAs($pausedWorker)
            ->get(route('worker.engagements.sessions.show', $paused->id))
            ->assertOk()
            ->assertSee('الارتباط متوقف مؤقتاً، لذلك لا يمكن بدء جلسة عمل جديدة حتى يُستأنف.')
            ->assertSee('متوقف مؤقتاً')
            ->assertDontSee('بدء جلسة العمل');

        $this->actingAs($completedWorker)
            ->get(route('worker.engagements.sessions.show', $completed->id))
            ->assertOk()
            ->assertSee('اكتمل هذا الارتباط، لذلك لا يمكن بدء جلسة عمل جديدة.')
            ->assertSee('مكتمل')
            ->assertDontSee('بدء جلسة العمل');

        $this->actingAs($terminatedWorker)
            ->get(route('worker.engagements.sessions.show', $terminated->id))
            ->assertOk()
            ->assertSee('أُنهي هذا الارتباط، لذلك لا يمكن بدء جلسة عمل جديدة.')
            ->assertSee('منهي')
            ->assertDontSee('بدء جلسة العمل');
    }

    public function test_worker_cannot_view_another_workers_session_history(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [$otherWorker] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id));

        $this->actingAs($otherWorker)
            ->get(route('worker.engagements.sessions.show', $engagement->id))
            ->assertNotFound();
    }

    public function test_company_can_view_only_sessions_for_its_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $worker, $profile);
        $engagementB = $this->createActiveEngagement($ownerB, $workspaceB, $worker, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagementA->id));

        $this->companyRequest($ownerA, $workspaceA)
            ->get(route('workspace.worker-engagements.sessions.index', $engagementA))
            ->assertOk()
            ->assertSee((string) $engagementA->id);

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-engagements.sessions.index', $engagementA))
            ->assertNotFound();

        $this->assertDatabaseMissing('worker_work_sessions', [
            'worker_engagement_id' => $engagementB->id,
        ]);
    }

    public function test_worker_work_session_status_is_a_backed_enum_and_invalid_transition_fails(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id));
        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('started', WorkerWorkSessionStatus::Started->value);
        $this->assertSame('ended', WorkerWorkSessionStatus::Ended->value);
        $this->assertInstanceOf(WorkerWorkSessionStatus::class, $session->status);

        $this->expectException(WorkerWorkSessionException::class);
        $session->transitionTo(WorkerWorkSessionStatus::Ended);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Session Workspace'): array
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

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
