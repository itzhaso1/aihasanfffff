<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Enums\WorkerEngagementStatus;
use App\Models\User;
use App\Models\Worker\WorkerContractRate;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Models\Worker\WorkerVerificationDocument;
use App\Models\Workspace;
use Database\Seeders\WorkerSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyWorkerDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerSkillSeeder::class);
    }

    public function test_company_api_lists_discoverable_workers_with_photo_filters_and_pagination(): void
    {
        [$owner, $workspace] = $this->createCompany();
        $skill = WorkerSkill::query()->firstOrFail();
        [, $visible] = $this->createWorker('Visible Worker', true, [
            'professional_summary' => 'Handles enterprise field accounts.',
            'sales_experience_level' => 'senior',
        ], 'worker-profile-photos/visible/photo.jpg', $skill);
        [, $hidden] = $this->createWorker('Hidden Worker', false);
        [, $unavailable] = $this->createWorker('Unavailable Worker', true, [
            'availability_status' => WorkerProfile::AVAILABILITY_UNAVAILABLE,
        ]);

        $response = $this->companyRequest($owner, $workspace)
            ->getJson('/api/mobile/v1/company/worker-discovery?search=enterprise&skill_key='.$skill->skill_key.'&per_page=1');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.display_name', $visible->display_name)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.skills.0.skill_key', $skill->skill_key);

        $photo = (string) $response->json('data.0.profile_photo');
        $this->assertNotSame('', $photo);
        $this->assertStringContainsString('worker-profile-photos', $photo);

        $response->assertJsonMissingPath('data.0.private_identity');
        $this->assertDatabaseCount('worker_profiles', 3);
        $this->assertStringNotContainsString($hidden->display_name, $response->getContent());
        $this->assertStringNotContainsString($unavailable->display_name, $response->getContent());
    }

    public function test_company_api_returns_a_private_safe_profile_and_scopes_existing_engagements_to_the_current_company(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [, $profile] = $this->createWorker('Shared Worker', true, [
            'professional_summary' => 'Professional summary.',
            'sales_experience_level' => 'senior',
        ], 'worker-profile-photos/shared/photo.jpg');
        $profile->privateIdentity()->create([
            'legal_name' => 'Private Legal Name',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'PRIVATE-IDENTITY-123',
            'address' => 'Private Address',
        ]);
        WorkerVerificationDocument::query()->create([
            'worker_profile_id' => $profile->id,
            'document_type_code' => 'identity_document',
            'disk' => 'local',
            'path' => 'private/worker-id.pdf',
            'original_name' => 'worker-id.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'status' => WorkerVerificationDocument::STATUS_APPROVED,
            'uploaded_by' => $profile->user_id,
        ]);

        $engagement = $this->createOffer($ownerA, $workspaceA, $profile);

        $companyAResponse = $this->companyRequest($ownerA, $workspaceA)
            ->getJson('/api/mobile/v1/company/worker-discovery/'.$profile->id);

        $companyAResponse
            ->assertOk()
            ->assertJsonPath('data.display_name', $profile->display_name)
            ->assertJsonPath('data.existing_engagement.id', $engagement->id)
            ->assertJsonPath('data.existing_engagement.status', WorkerEngagementStatus::Offered->value)
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.date_of_birth')
            ->assertJsonMissingPath('data.identity_number')
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.documents')
            ->assertJsonMissingPath('data.review_notes');

        $companyBResponse = $this->companyRequest($ownerB, $workspaceB)
            ->getJson('/api/mobile/v1/company/worker-discovery/'.$profile->id);

        $companyBResponse
            ->assertOk()
            ->assertJsonPath('data.display_name', $profile->display_name)
            ->assertJsonMissingPath('data.existing_engagement.id')
            ->assertJsonMissingPath('data.legal_name');
    }

    public function test_company_api_refreshes_the_current_engagement_status_after_worker_acceptance(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Accepted Worker', true, [
            'profile_photo' => 'worker-profile-photos/accepted/photo.jpg',
        ]);
        WorkerContractRate::factory()->create([
            'worker_profile_id' => $profile->id,
            'duration_value' => 2,
            'duration_unit' => 'week',
            'amount_minor' => 60000,
            'currency' => 'SAR',
            'effective_from' => now()->subMinute(),
        ]);
        $engagement = $this->createOffer($owner, $workspace, $profile, [
            'job_title' => 'Accepted Sales Specialist',
            'starts_at' => '2026-10-01',
            'duration_value' => 2,
            'duration_unit' => 'week',
        ]);

        Sanctum::actingAs($worker);
        $this->postJson('/api/mobile/v1/worker/engagements/'.$engagement->id.'/accept')
            ->assertOk();

        $this->companyRequest($owner, $workspace)
            ->getJson('/api/mobile/v1/company/worker-discovery/'.$profile->id)
            ->assertOk()
            ->assertJsonPath('data.existing_engagement.status', WorkerEngagementStatus::Accepted->value)
            ->assertJsonPath('data.existing_engagement.job_title', 'Accepted Sales Specialist')
            ->assertJsonPath('data.existing_engagement.starts_at', '2026-10-01')
            ->assertJsonPath('data.existing_engagement.ends_at', '2026-10-15')
            ->assertJsonPath('data.existing_engagement.duration_value', 2)
            ->assertJsonPath('data.existing_engagement.duration_unit', 'week');
    }

    public function test_company_api_lists_only_current_workspace_open_contracts(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');

        [$acceptedWorker, $acceptedProfile] = $this->createWorker('Accepted Worker');
        $accepted = $this->createOffer($ownerA, $workspaceA, $acceptedProfile);
        $this->acceptOffer($acceptedWorker, $accepted);

        [$activeWorker, $activeProfile] = $this->createWorker('Active Worker');
        $active = $this->createOffer($ownerA, $workspaceA, $activeProfile);
        $this->acceptOffer($activeWorker, $active);
        $this->activateEngagement($ownerA, $workspaceA, $active);

        [$pausedWorker, $pausedProfile] = $this->createWorker('Paused Worker');
        $paused = $this->createOffer($ownerA, $workspaceA, $pausedProfile);
        $this->acceptOffer($pausedWorker, $paused);
        $this->activateEngagement($ownerA, $workspaceA, $paused);
        $this->pauseEngagement($ownerA, $workspaceA, $paused);

        [, $offeredProfile] = $this->createWorker('Offered Worker');
        $offered = $this->createOffer($ownerA, $workspaceA, $offeredProfile);

        [$completedWorker, $completedProfile] = $this->createWorker('Completed Worker');
        $completed = $this->createOffer($ownerA, $workspaceA, $completedProfile);
        $this->acceptOffer($completedWorker, $completed);
        $this->activateEngagement($ownerA, $workspaceA, $completed);
        $this->completeEngagement($ownerA, $workspaceA, $completed);

        [$terminatedWorker, $terminatedProfile] = $this->createWorker('Terminated Worker');
        $terminated = $this->createOffer($ownerA, $workspaceA, $terminatedProfile);
        $this->acceptOffer($terminatedWorker, $terminated);
        $this->activateEngagement($ownerA, $workspaceA, $terminated);
        $this->terminateEngagement($ownerA, $workspaceA, $terminated);

        [$otherWorker, $otherProfile] = $this->createWorker('Other Workspace Worker');
        $other = $this->createOffer($ownerB, $workspaceB, $otherProfile);
        $this->acceptOffer($otherWorker, $other);

        $response = $this->companyRequest($ownerA, $workspaceA)
            ->getJson('/api/mobile/v1/company/worker-engagements/open')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $ids = collect($response->json('data'))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $expectedIds = collect([$accepted, $active, $paused])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedIds, $ids);
        $this->assertStringNotContainsString('Other Workspace Worker', $response->getContent());
        $this->assertJsonPathForEngagement($response->json('data'), $accepted->id, 'accepted');

        $this->companyRequest($ownerB, $workspaceB)
            ->getJson('/api/mobile/v1/company/worker-engagements/open')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $other->id);
    }

    public function test_company_api_creates_an_offer_without_auto_activation_and_rejects_duplicates(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$member, $memberWorkspace] = $this->createCompanyMember($workspace);
        [, $profile] = $this->createWorker('Offer Candidate', true, [
            'professional_summary' => 'Available for projects.',
            'sales_experience_level' => 'senior',
        ]);

        $this->companyRequest($owner, $workspace)
            ->postJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/engagements', [
                'job_title' => 'Independent Sales Specialist',
                'job_description' => 'Sell and explain the company catalog.',
                'starts_at' => '2026-10-01',
                'ends_at' => '2026-12-31',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WorkerEngagementStatus::Offered->value)
            ->assertJsonPath('data.worker_profile_id', $profile->id)
            ->assertJsonPath('data.job_description', 'Sell and explain the company catalog.');

        $this->assertDatabaseHas('worker_engagements', [
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Offered->value,
            'job_title' => 'Independent Sales Specialist',
            'job_description' => 'Sell and explain the company catalog.',
        ]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $profile->user_id]);

        $this->companyRequest($owner, $workspace)
            ->postJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/engagements', [
                'job_title' => 'Duplicate Offer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->companyRequest($member, $memberWorkspace)
            ->postJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/engagements', [
                'job_title' => 'Member Offer',
            ])
            ->assertForbidden();
    }

    public function test_company_api_calculates_the_end_date_from_duration(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createWorker('Duration Worker', true);
        WorkerContractRate::factory()->create([
            'worker_profile_id' => $profile->id,
            'duration_value' => 3,
            'duration_unit' => 'month',
            'amount_minor' => 200000,
            'currency' => 'SAR',
            'effective_from' => now()->subMinute(),
        ]);

        $response = $this->companyRequest($owner, $workspace)
            ->postJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/engagements', [
                'job_title' => 'Independent Sales Specialist',
                'starts_at' => '2026-10-01',
                'duration_value' => 3,
                'duration_unit' => 'month',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-10-01')
            ->assertJsonPath('data.ends_at', '2027-01-01')
            ->assertJsonPath('data.duration_value', 3)
            ->assertJsonPath('data.duration_unit', 'month');

        $this->assertDatabaseHas('worker_engagements', [
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'starts_at' => '2026-10-01 00:00:00',
            'ends_at' => '2027-01-01 00:00:00',
            'duration_value' => 3,
            'duration_unit' => 'month',
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Discovery Company'): array
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
     * @return array{0: User, 1: Workspace}
     */
    private function createCompanyMember(Workspace $workspace): array
    {
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$member, $workspace];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(
        string $name,
        bool $discoverable = true,
        array $overrides = [],
        ?string $profilePhoto = null,
        ?WorkerSkill $skill = null,
    ): array {
        $user = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create(array_merge([
            'user_id' => $user->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent sales specialist.',
            'sales_experience_level' => 'senior',
            'profile_photo' => $profilePhoto,
        ], $overrides));
        $profile->discoverySettings()->create([
            'is_discoverable' => $discoverable,
        ]);

        if ($skill !== null) {
            $profile->skills()->attach($skill->id);
        }

        return [$user, $profile];
    }

    private function createOffer(
        User $owner,
        Workspace $workspace,
        WorkerProfile $profile,
        array $overrides = [],
    ): WorkerEngagement {
        $payload = array_merge([
            'job_title' => 'Existing Offer',
        ], $overrides);

        $this->companyRequest($owner, $workspace)
            ->postJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/engagements', [
                ...$payload,
            ])
            ->assertOk();

        return WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->latest('id')
            ->firstOrFail();
    }

    private function acceptOffer(User $worker, WorkerEngagement $engagement): void
    {
        Sanctum::actingAs($worker);

        $this->postJson('/api/mobile/v1/worker/engagements/'.$engagement->id.'/accept')
            ->assertOk();
    }

    private function activateEngagement(
        User $owner,
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): void {
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.worker-engagements.activate', $engagement))
            ->assertRedirect();
    }

    private function pauseEngagement(
        User $owner,
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): void {
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.worker-engagements.pause', $engagement))
            ->assertRedirect();
    }

    private function completeEngagement(
        User $owner,
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): void {
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.worker-engagements.complete', $engagement))
            ->assertRedirect();
    }

    private function terminateEngagement(
        User $owner,
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): void {
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.worker-engagements.terminate', $engagement), [
                'reason' => 'Ended for test isolation.',
            ])
            ->assertRedirect();
    }

    /**
     * @param  list<array<string, mixed>>  $engagements
     */
    private function assertJsonPathForEngagement(
        array $engagements,
        int $engagementId,
        string $status,
    ): void {
        $engagement = collect($engagements)->firstWhere('id', $engagementId);

        $this->assertIsArray($engagement);
        $this->assertSame($status, $engagement['status']);
    }

    private function companyRequest(User $user, Workspace $workspace): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
