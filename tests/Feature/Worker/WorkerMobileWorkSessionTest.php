<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkerWorkSessionSegmentType;
use App\Enums\WorkerWorkSessionStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Worker\WorkerWorkSessionSegment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerMobileWorkSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_reads_owned_sessions_with_segments_and_duration(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.session', $engagement))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->travelTo('2026-09-27 10:00:00');
        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))
            ->assertOk();
        $this->travelTo('2026-09-27 10:05:00');
        $this->postJson(route('mobile.v1.worker.engagements.session.end', $engagement))
            ->assertOk();

        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();
        $segment = WorkerWorkSessionSegment::withoutGlobalScopes()
            ->where('worker_work_session_id', $session->id)
            ->firstOrFail();

        $this->getJson(route('mobile.v1.worker.engagements.session', $engagement))
            ->assertOk()
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.worker_engagement_id', $engagement->id)
            ->assertJsonPath('data.0.status', WorkerWorkSessionStatus::Ended->value)
            ->assertJsonPath('data.0.started_at', $session->started_at?->toIso8601String())
            ->assertJsonPath('data.0.ended_at', $session->ended_at?->toIso8601String())
            ->assertJsonPath('data.0.duration_seconds', 300)
            ->assertJsonPath('data.0.segments.0.segment_type', WorkerWorkSessionSegmentType::Active->value)
            ->assertJsonPath('data.0.segments.0.ended_at', $segment->ended_at?->toIso8601String())
            ->assertJsonMissingPath('data.0.active_session_key')
            ->assertJsonMissingPath('data.0.started_by_user_id')
            ->assertJsonMissingPath('data.0.ended_by_user_id')
            ->assertJsonMissingPath('data.0.activity_events')
            ->assertJsonMissingPath('data.1');
    }

    public function test_foreign_worker_session_routes_return_404(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [$other] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);

        Sanctum::actingAs($worker);
        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))->assertOk();

        Sanctum::actingAs($other);
        foreach ([
            ['get', route('mobile.v1.worker.engagements.session', $engagement)],
            ['post', route('mobile.v1.worker.engagements.session.start', $engagement)],
            ['post', route('mobile.v1.worker.engagements.session.break', $engagement)],
            ['post', route('mobile.v1.worker.engagements.session.resume', $engagement)],
            ['post', route('mobile.v1.worker.engagements.session.end', $engagement)],
        ] as [$method, $url]) {
            $this->json($method, $url)
                ->assertNotFound()
                ->assertJsonPath('message', 'غير موجود.')
                ->assertJsonMissingPath('data.status')
                ->assertJsonMissingPath('data.0.status');
        }

        $this->assertSame(WorkerWorkSessionStatus::Started, WorkerWorkSession::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_unauthenticated_session_start_returns_401(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);

        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))
            ->assertUnauthorized();
    }

    public function test_start_uses_the_server_clock_and_rejects_a_second_started_session(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        Sanctum::actingAs($worker);
        $this->travelTo('2026-09-27 10:00:00');

        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement), [
            'started_at' => '2000-01-01T00:00:00Z',
            'ended_at' => '2000-01-01T01:00:00Z',
            'duration_seconds' => 999999,
            'worker_id' => 1,
            'worker_profile_id' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', WorkerWorkSessionStatus::Started->value)
            ->assertJsonPath('data.duration_seconds', null)
            ->assertJsonPath('data.segments.0.segment_type', WorkerWorkSessionSegmentType::Active->value)
            ->assertJsonPath('data.segments.0.ended_at', null)
            ->assertJsonMissingPath('data.segments.1');

        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('2026-09-27 10:00:00', $session->started_at?->format('Y-m-d H:i:s'));
        $this->assertNull($session->duration_seconds);

        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The worker engagement already has an active work session.');

        $this->assertSame(1, WorkerWorkSession::withoutGlobalScopes()->count());
    }

    public function test_start_rejects_an_unverified_worker(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        $profile->forceFill([
            'verification_status' => WorkerProfile::VERIFICATION_PENDING_REVIEW,
        ])->save();
        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The worker is not eligible to start a work session.');

        $this->assertSame(0, WorkerWorkSession::withoutGlobalScopes()->count());
    }

    public function test_start_rejects_a_worker_who_is_not_verified_in_lifecycle(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        $profile->forceFill([
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
        ])->save();
        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The worker is not eligible to start a work session.');
    }

    public function test_start_rejects_an_engagement_that_is_not_active(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Accepted);
        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A work session requires an active worker engagement.');
    }

    public function test_break_closes_the_active_segment_and_rejects_a_second_break(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.session.break', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is no active work session for this engagement.');

        $this->travelTo('2026-09-27 10:00:00');
        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))->assertOk();
        $this->travelTo('2026-09-27 10:15:00');

        $this->postJson(route('mobile.v1.worker.engagements.session.break', $engagement))
            ->assertOk()
            ->assertJsonPath('data.status', WorkerWorkSessionStatus::Started->value)
            ->assertJsonPath('data.segments.0.segment_type', WorkerWorkSessionSegmentType::Active->value)
            ->assertJsonPath('data.segments.1.segment_type', WorkerWorkSessionSegmentType::Break->value)
            ->assertJsonPath('data.segments.1.ended_at', null);

        $segments = WorkerWorkSessionSegment::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame('2026-09-27 10:15:00', $segments[0]->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-27 10:15:00', $segments[1]->started_at?->format('Y-m-d H:i:s'));
        $this->assertNull($segments[1]->ended_at);

        $this->postJson(route('mobile.v1.worker.engagements.session.break', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A work-session segment cannot transition from [break] to [break].');
    }

    public function test_resume_after_break_opens_an_active_segment(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        Sanctum::actingAs($worker);
        $this->travelTo('2026-09-27 10:00:00');
        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))->assertOk();
        $this->postJson(route('mobile.v1.worker.engagements.session.break', $engagement))->assertOk();
        $this->travelTo('2026-09-27 10:20:00');

        $this->postJson(route('mobile.v1.worker.engagements.session.resume', $engagement))
            ->assertOk()
            ->assertJsonPath('data.status', WorkerWorkSessionStatus::Started->value)
            ->assertJsonPath('data.segments.1.segment_type', WorkerWorkSessionSegmentType::Break->value)
            ->assertJsonPath('data.segments.2.segment_type', WorkerWorkSessionSegmentType::Active->value)
            ->assertJsonPath('data.segments.2.ended_at', null);

        $active = WorkerWorkSessionSegment::withoutGlobalScopes()
            ->where('segment_type', WorkerWorkSessionSegmentType::Active->value)
            ->whereNull('ended_at')
            ->firstOrFail();
        $this->assertSame('2026-09-27 10:20:00', $active->started_at?->format('Y-m-d H:i:s'));

        $this->postJson(route('mobile.v1.worker.engagements.session.resume', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A work-session segment cannot transition from [active] to [active].');
    }

    public function test_resume_after_idle_opens_an_active_segment(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        Sanctum::actingAs($worker);
        $this->travelTo('2026-09-27 10:00:00');
        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))->assertOk();

        $open = WorkerWorkSessionSegment::withoutGlobalScopes()->whereNull('ended_at')->firstOrFail();
        $this->travelTo('2026-09-27 10:10:00');
        $open->closeAt(now());
        WorkerWorkSessionSegment::withoutGlobalScopes()->create([
            'workspace_id' => $open->workspace_id,
            'worker_work_session_id' => $open->worker_work_session_id,
            'segment_type' => WorkerWorkSessionSegmentType::Idle,
            'started_at' => now(),
        ]);
        $this->travelTo('2026-09-27 10:25:00');

        $this->postJson(route('mobile.v1.worker.engagements.session.resume', $engagement))
            ->assertOk()
            ->assertJsonPath('data.segments.1.segment_type', WorkerWorkSessionSegmentType::Idle->value)
            ->assertJsonPath('data.segments.2.segment_type', WorkerWorkSessionSegmentType::Active->value)
            ->assertJsonPath('data.segments.2.ended_at', null);

        $resumed = WorkerWorkSessionSegment::withoutGlobalScopes()
            ->where('segment_type', WorkerWorkSessionSegmentType::Active->value)
            ->whereNull('ended_at')
            ->firstOrFail();
        $this->assertSame('2026-09-27 10:25:00', $resumed->started_at?->format('Y-m-d H:i:s'));
    }

    public function test_end_uses_the_server_duration_and_ignores_a_client_duration(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        Sanctum::actingAs($worker);
        $this->travelTo('2026-09-27 10:00:00');
        $this->postJson(route('mobile.v1.worker.engagements.session.start', $engagement))->assertOk();
        $this->travelTo('2026-09-27 10:05:00');

        $this->postJson(route('mobile.v1.worker.engagements.session.end', $engagement), [
            'ended_at' => '2000-01-01T00:00:00Z',
            'duration_seconds' => 1,
            'worker_id' => 9,
        ])->assertOk()
            ->assertJsonPath('data.status', WorkerWorkSessionStatus::Ended->value)
            ->assertJsonPath('data.duration_seconds', 300)
            ->assertJsonPath('data.segments.0.ended_at', WorkerWorkSession::withoutGlobalScopes()->firstOrFail()->ended_at?->toIso8601String());

        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('2026-09-27 10:05:00', $session->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame(300, $session->duration_seconds);
    }

    public function test_end_without_a_started_session_returns_422(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active);
        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.session.end', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is no active work session for this engagement.');
    }

    public function test_end_rejects_an_engagement_that_cannot_close_a_session(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Accepted);
        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.session.end', $engagement))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This engagement cannot close a work session in its current state.');
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
            'name' => 'Session Company',
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
        WorkerEngagementStatus $status,
    ): WorkerEngagement {
        return WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => $status,
            'job_title' => 'Independent Worker',
            'created_by' => $owner->id,
        ]);
    }
}
