<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkerWorkSessionStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Workspace;
use App\Services\Worker\WorkerEngagementAuthorization;
use App\Services\Worker\WorkerEngagementException;
use App\Services\Worker\WorkerEngagementService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WorkerEngagementExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_scheduler_registers_worker_engagement_expiration_with_overlap_protection(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) ($event->command ?? ''),
                'worker:engagements:expire',
            ));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_expiration_command_completes_an_expired_active_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());
        $this->acceptAndActivate($owner, $workspace, $worker, $engagement);
        $this->setEndsAt($workspace, $engagement, now()->subMinute());

        $this->artisan('worker:engagements:expire')
            ->expectsOutput('Completed 1 worker engagements.')
            ->assertExitCode(0);

        $completed = WorkerEngagement::withoutGlobalScopes()->findOrFail($engagement->id);
        $this->assertSame(WorkerEngagementStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Completed->value,
        ]);

        $audit = AuditLog::withoutGlobalScopes()
            ->where('entity_type', WorkerEngagement::class)
            ->where('entity_id', $engagement->id)
            ->where('action', 'worker.engagement.transitioned')
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($audit->user_id);
        $this->assertSame('worker_engagement_expiration', $audit->meta['source']);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.show', $engagement))
            ->assertOk()
            ->assertSee('مكتمل');
    }

    public function test_expiration_command_completes_an_expired_paused_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());
        $this->acceptAndActivate($owner, $workspace, $worker, $engagement);

        $this->inWorkspace($workspace, function () use ($owner, $workspace, $engagement): void {
            app(WorkerEngagementService::class)->pause($workspace, $owner, $engagement);
        });
        $this->setEndsAt($workspace, $engagement, now()->subMinute());

        $this->artisan('worker:engagements:expire')
            ->expectsOutput('Completed 1 worker engagements.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Completed->value,
        ]);
    }

    public function test_future_active_and_paused_engagements_remain_unchanged(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$activeWorker, $activeProfile] = $this->createWorker();
        [$pausedWorker, $pausedProfile] = $this->createWorker();
        $active = $this->createOffer($owner, $workspace, $activeProfile, now()->addHour());
        $paused = $this->createOffer($owner, $workspace, $pausedProfile, now()->addHour());
        $this->acceptAndActivate($owner, $workspace, $activeWorker, $active);
        $this->acceptAndActivate($owner, $workspace, $pausedWorker, $paused);
        $this->inWorkspace($workspace, function () use ($owner, $workspace, $paused): void {
            app(WorkerEngagementService::class)->pause($workspace, $owner, $paused);
        });

        $this->artisan('worker:engagements:expire')
            ->expectsOutput('Completed 0 worker engagements.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $active->id,
            'status' => WorkerEngagementStatus::Active->value,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $paused->id,
            'status' => WorkerEngagementStatus::Paused->value,
        ]);
    }

    public function test_accepted_engagement_is_not_completed_until_company_activates_it(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());

        app(WorkerEngagementService::class)->accept($worker, $engagement->id);
        $this->setEndsAt($workspace, $engagement, now()->subMinute());

        $this->artisan('worker:engagements:expire')
            ->expectsOutput('Completed 0 worker engagements.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Accepted->value,
        ]);
    }

    public function test_terminal_and_draft_engagements_are_ignored(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$completedWorker, $completedProfile] = $this->createWorker();
        [$terminatedWorker, $terminatedProfile] = $this->createWorker();
        [$rejectedWorker, $rejectedProfile] = $this->createWorker();
        $completed = $this->createOffer($owner, $workspace, $completedProfile, now()->addHour());
        $terminated = $this->createOffer($owner, $workspace, $terminatedProfile, now()->addHour());
        $rejected = $this->createOffer($owner, $workspace, $rejectedProfile, now()->addHour());

        $this->acceptAndActivate($owner, $workspace, $completedWorker, $completed);
        $this->inWorkspace($workspace, function () use ($owner, $workspace, $completed): void {
            app(WorkerEngagementService::class)->complete($workspace, $owner, $completed);
        });
        $this->acceptAndActivate($owner, $workspace, $terminatedWorker, $terminated);
        $this->inWorkspace($workspace, function () use ($owner, $workspace, $terminated): void {
            app(WorkerEngagementService::class)->terminate($workspace, $owner, $terminated, 'انتهت الحاجة.');
        });
        app(WorkerEngagementService::class)->reject($rejectedWorker, $rejected->id);
        $draft = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $rejectedProfile->id,
            'status' => WorkerEngagementStatus::Draft,
            'ends_at' => now()->subMinute(),
            'created_by' => $owner->id,
        ]);

        foreach ([$completed, $terminated, $rejected] as $engagement) {
            $this->setEndsAt($workspace, $engagement, now()->subMinute());
        }

        $this->artisan('worker:engagements:expire')
            ->expectsOutput('Completed 0 worker engagements.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $completed->id,
            'status' => WorkerEngagementStatus::Completed->value,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $terminated->id,
            'status' => WorkerEngagementStatus::Terminated->value,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $rejected->id,
            'status' => WorkerEngagementStatus::Rejected->value,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $draft->id,
            'status' => WorkerEngagementStatus::Draft->value,
        ]);
    }

    public function test_expired_offer_cannot_be_accepted(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());
        $this->setEndsAt($workspace, $engagement, now()->subMinute());

        try {
            app(WorkerEngagementService::class)->accept($worker, $engagement->id);
            $this->fail('An expired worker engagement offer should not be accepted.');
        } catch (WorkerEngagementException $exception) {
            $this->assertSame('انتهت صلاحية هذا العرض ولا يمكن قبوله.', $exception->getMessage());
        }

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Offered->value,
        ]);
        $this->assertDatabaseCount('worker_engagement_acceptances', 0);
    }

    public function test_non_expired_offer_keeps_existing_acceptance_behavior(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());

        $accepted = app(WorkerEngagementService::class)->accept($worker, $engagement->id);

        $this->assertSame(WorkerEngagementStatus::Accepted, $accepted->status);
        $this->assertDatabaseHas('worker_engagement_acceptances', [
            'worker_engagement_id' => $engagement->id,
            'decision' => WorkerEngagementStatus::Accepted->value,
        ]);
    }

    public function test_expiration_is_idempotent_and_does_not_duplicate_audits(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());
        $this->acceptAndActivate($owner, $workspace, $worker, $engagement);
        $this->setEndsAt($workspace, $engagement, now()->subMinute());

        $this->artisan('worker:engagements:expire')
            ->expectsOutput('Completed 1 worker engagements.')
            ->assertExitCode(0);
        $this->artisan('worker:engagements:expire')
            ->expectsOutput('Completed 0 worker engagements.')
            ->assertExitCode(0);

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('entity_type', WorkerEngagement::class)
            ->where('entity_id', $engagement->id)
            ->where('action', 'worker.engagement.transitioned')
            ->whereJsonContains('meta->source', 'worker_engagement_expiration')
            ->count());
    }

    public function test_completed_engagement_cannot_start_a_new_worker_session_after_expiration(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());
        $this->acceptAndActivate($owner, $workspace, $worker, $engagement);
        $this->setEndsAt($workspace, $engagement, now()->subMinute());

        $this->artisan('worker:engagements:expire')->assertExitCode(0);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertSessionHasErrors('session');

        $this->assertDatabaseCount('worker_work_sessions', 0);
    }

    public function test_existing_capability_is_not_authorized_after_automatic_completion(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());
        $this->acceptAndActivate($owner, $workspace, $worker, $engagement);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                'capability_key' => 'customers.view',
            ])
            ->assertRedirect();

        $authorization = app(WorkerEngagementAuthorization::class);
        $this->assertTrue($authorization->workerCan(
            $worker,
            $workspace,
            $engagement,
            'customers.view',
        ));

        $this->setEndsAt($workspace, $engagement, now()->subMinute());
        $this->artisan('worker:engagements:expire')->assertExitCode(0);

        $this->assertFalse($authorization->workerCan(
            $worker,
            $workspace,
            $engagement,
            'customers.view',
        ));
        $this->assertDatabaseHas('worker_engagement_permissions', [
            'worker_engagement_id' => $engagement->id,
            'capability_key' => 'customers.view',
            'allowed' => true,
        ]);
    }

    public function test_open_session_is_preserved_without_fabricated_completion_data(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());
        $this->acceptAndActivate($owner, $workspace, $worker, $engagement);

        $this->actingAs($worker)
            ->post(route('worker.engagements.sessions.start', $engagement->id))
            ->assertRedirect();
        $this->setEndsAt($workspace, $engagement, now()->subMinute());

        $this->artisan('worker:engagements:expire')->assertExitCode(0);

        $session = WorkerWorkSession::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(WorkerWorkSessionStatus::Started, $session->status);
        $this->assertNull($session->ended_at);
        $this->assertNull($session->duration_seconds);
    }

    public function test_expiration_does_not_change_an_ineligible_engagement_in_another_workspace(): void
    {
        [$owner, $workspace] = $this->createCompany('Primary Workspace');
        [$otherOwner, $otherWorkspace] = $this->createCompany('Other Workspace');
        [$worker, $profile] = $this->createWorker();
        $expiredActive = $this->createOffer($owner, $workspace, $profile, now()->addHour());
        $this->acceptAndActivate($owner, $workspace, $worker, $expiredActive);
        $this->setEndsAt($workspace, $expiredActive, now()->subMinute());

        $otherOffer = $this->createOffer($otherOwner, $otherWorkspace, $profile, now()->subMinute());

        $this->artisan('worker:engagements:expire')
            ->expectsOutput('Completed 1 worker engagements.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $expiredActive->id,
            'status' => WorkerEngagementStatus::Completed->value,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $otherOffer->id,
            'status' => WorkerEngagementStatus::Offered->value,
        ]);
    }

    public function test_another_worker_cannot_accept_an_engagement_offer(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker();
        [$otherWorker] = $this->createWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile, now()->addHour());

        $this->expectException(ModelNotFoundException::class);
        app(WorkerEngagementService::class)->accept($otherWorker, $engagement->id);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Test Workspace'): array
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
    private function createWorker(): array
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
            'professional_summary' => 'Independent worker.',
            'sales_experience_level' => 'senior',
        ]);

        return [$worker, $profile];
    }

    private function createOffer(
        User $owner,
        Workspace $workspace,
        WorkerProfile $profile,
        CarbonInterface $endsAt,
    ): WorkerEngagement {
        $endsAt = CarbonImmutable::instance($endsAt);

        return $this->inWorkspace($workspace, function () use ($owner, $workspace, $profile, $endsAt): WorkerEngagement {
            return app(WorkerEngagementService::class)->createOffer(
                $workspace,
                $owner,
                [
                    'worker_profile_id' => $profile->id,
                    'job_title' => 'Independent Worker',
                    'starts_at' => $endsAt->subHour()->toDateTimeString(),
                    'ends_at' => $endsAt->toDateTimeString(),
                ],
            );
        });
    }

    private function acceptAndActivate(
        User $owner,
        Workspace $workspace,
        User $worker,
        WorkerEngagement $engagement,
    ): void {
        app(WorkerEngagementService::class)->accept($worker, $engagement->id);
        $this->inWorkspace($workspace, function () use ($owner, $workspace, $engagement): void {
            app(WorkerEngagementService::class)->activate($workspace, $owner, $engagement);
        });
    }

    private function setEndsAt(
        Workspace $workspace,
        WorkerEngagement $engagement,
        CarbonInterface $endsAt,
    ): void {
        $this->inWorkspace($workspace, function () use ($engagement, $endsAt): void {
            $fresh = WorkerEngagement::query()->whereKey($engagement->id)->firstOrFail();
            $fresh->forceFill(['ends_at' => $endsAt])->save();
        });
    }

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
