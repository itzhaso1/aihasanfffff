<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerActivityEventType;
use App\Models\User;
use App\Models\Worker\WorkerActivityEvent;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Workspace;
use App\Services\Worker\WorkerActivityEventException;
use App\Services\Worker\WorkerActivityEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerActivityEventTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_session_boundaries_create_trusted_engagement_scoped_events(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->travelTo('2026-09-27 10:00:00');
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id));
        $this->travelTo('2026-09-27 10:30:00');
        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.end', $engagement->id));

        $events = WorkerActivityEvent::withoutGlobalScopes()
            ->where('worker_engagement_id', $engagement->id)
            ->orderBy('occurred_at')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame(WorkerActivityEventType::SessionStarted, $events[0]->event_type);
        $this->assertSame(WorkerActivityEventType::SessionEnded, $events[1]->event_type);
        $this->assertSame($workspace->id, $events[0]->workspace_id);
        $this->assertSame($profile->id, $events[0]->worker_profile_id);
        $this->assertSame($worker->id, $events[0]->actor_user_id);
        $this->assertSame($events[0]->worker_work_session_id, $events[1]->worker_work_session_id);
        $this->assertSame('2026-09-27 10:00:00', $events[0]->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-27 10:30:00', $events[1]->occurred_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.activity_event.recorded',
        ]);
    }

    public function test_page_access_and_heartbeat_like_input_do_not_create_activity(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->actingAs($worker)
            ->get(route('worker.engagements.sessions.show', $engagement->id))
            ->assertOk();
        $this->assertDatabaseCount('worker_activity_events', 0);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id), [
                'heartbeat' => true,
                'occurred_at' => '2099-01-01 00:00:00',
                'payload' => ['fake_activity' => true],
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('worker_activity_events', 1);
        $this->assertDatabaseMissing('worker_activity_events', [
            'event_type' => 'heartbeat',
        ]);
        $this->assertDatabaseHas('worker_activity_events', [
            'event_type' => WorkerActivityEventType::SessionStarted->value,
        ]);
    }

    public function test_activity_event_service_rejects_an_actor_from_another_worker_profile(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [$otherWorker] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id));
        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();

        $this->expectException(WorkerActivityEventException::class);
        app(WorkerActivityEventService::class)->recordSessionEvent(
            $session,
            $otherWorker,
            WorkerActivityEventType::SessionStarted,
        );
    }

    public function test_activity_events_are_append_only_and_cannot_be_updated(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id));
        $event = WorkerActivityEvent::withoutGlobalScopes()->firstOrFail();

        $this->expectException(WorkerActivityEventException::class);
        $event->forceFill(['payload' => ['tampered' => true]])->save();
    }

    public function test_activity_events_are_append_only_and_cannot_be_deleted(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id));
        $event = WorkerActivityEvent::withoutGlobalScopes()->firstOrFail();

        $this->expectException(WorkerActivityEventException::class);
        $event->delete();
    }

    public function test_company_activity_view_is_read_only_and_workspace_scoped(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $worker, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagementA->id));

        $this->companyRequest($ownerA, $workspaceA)
            ->get(route('workspace.worker-engagements.sessions.index', $engagementA))
            ->assertOk()
            ->assertSee(WorkerActivityEventType::SessionStarted->value);

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-engagements.sessions.index', $engagementA))
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Activity Workspace'): array
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

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
