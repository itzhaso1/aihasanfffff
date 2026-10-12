<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerVerificationDocument;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_only_verified_discoverable_professional_profiles_appear(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $visible] = $this->createWorker('Visible Worker');
        [, $hidden] = $this->createWorker('Hidden Worker', discoverable: false);
        [, $unverified] = $this->createWorker('Unverified Worker', [
            'verification_status' => WorkerProfile::VERIFICATION_PENDING_REVIEW,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
        ]);
        [, $suspended] = $this->createWorker('Suspended Worker', [
            'verification_status' => WorkerProfile::VERIFICATION_SUSPENDED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_SUSPENDED,
        ]);
        [, $unavailable] = $this->createWorker('Unavailable Worker', [
            'availability_status' => WorkerProfile::AVAILABILITY_UNAVAILABLE,
        ]);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index'))
            ->assertOk()
            ->assertSee($visible->display_name)
            ->assertDontSee($hidden->display_name)
            ->assertDontSee($unverified->display_name)
            ->assertDontSee($suspended->display_name)
            ->assertDontSee($unavailable->display_name);
    }

    public function test_search_uses_professional_fields_and_not_private_identity_fields(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $target] = $this->createWorker('Senior Field Specialist', [
            'professional_summary' => 'Handles enterprise field accounts.',
            'sales_experience_level' => 'senior',
        ]);
        [, $other] = $this->createWorker('Junior Support Specialist', [
            'professional_summary' => 'Supports customer onboarding.',
            'sales_experience_level' => 'junior',
        ]);
        $target->privateIdentity()->create([
            'legal_name' => 'Private Legal Identity',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'PRIVATE-DISCOVERY-ID',
        ]);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index', ['search' => 'enterprise']))
            ->assertOk()
            ->assertSee($target->display_name)
            ->assertDontSee($other->display_name);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index', ['search' => 'PRIVATE-DISCOVERY-ID']))
            ->assertOk()
            ->assertDontSee($target->display_name);
    }

    public function test_supported_country_availability_and_experience_filters_are_applied(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $saudi] = $this->createWorker('Saudi Senior', [
            'country_code' => WorkerProfile::COUNTRY_SAUDI_ARABIA,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'sales_experience_level' => 'senior',
        ]);
        [, $jordanian] = $this->createWorker('Jordanian Senior', [
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'availability_status' => WorkerProfile::AVAILABILITY_LIMITED,
            'sales_experience_level' => 'senior',
        ]);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index', [
                'country_code' => WorkerProfile::COUNTRY_SAUDI_ARABIA,
                'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
                'sales_experience_level' => 'senior',
            ]))
            ->assertOk()
            ->assertSee($saudi->display_name)
            ->assertDontSee($jordanian->display_name);
    }

    public function test_discovery_profile_uses_an_explicit_professional_allowlist(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Public Professional');
        $profile->privateIdentity()->create([
            'legal_name' => 'Private Legal Identity',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'PRIVATE-DISCOVERY-ID',
            'address' => 'Private Home Address',
        ]);
        WorkerVerificationDocument::query()->create([
            'worker_profile_id' => $profile->id,
            'document_type_code' => 'identity_document',
            'disk' => 'local',
            'path' => 'private/worker-id.pdf',
            'original_name' => 'private-id.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'status' => WorkerVerificationDocument::STATUS_APPROVED,
            'uploaded_by' => $worker->id,
        ]);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.show', $profile->id))
            ->assertOk()
            ->assertSee($profile->display_name)
            ->assertSee($profile->professional_summary)
            ->assertDontSee($worker->email)
            ->assertDontSee('Private Legal Identity')
            ->assertDontSee('PRIVATE-DISCOVERY-ID')
            ->assertDontSee('Private Home Address')
            ->assertDontSee('private-id.pdf');
    }

    public function test_existing_company_relationship_is_visible_only_to_that_company(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [, $profile] = $this->createWorker('Shared Professional');
        $engagement = $this->createOffer($ownerA, $workspaceA, $profile);

        $this->companyRequest($ownerA, $workspaceA)
            ->get(route('workspace.worker-discovery.show', $profile->id))
            ->assertOk()
            ->assertSee('العلاقة الحالية مع مساحة العمل')
            ->assertSee($engagement->status->label());

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-discovery.show', $profile->id))
            ->assertOk()
            ->assertDontSee('العلاقة الحالية مع مساحة العمل')
            ->assertDontSee($engagement->status->label());
    }

    public function test_worker_discovery_shows_current_open_contracts_with_lifecycle_labels(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Accepted Professional');
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $engagement))
            ->assertRedirect();

        $response = $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index'));

        $openEngagements = $response->viewData('openEngagements');

        $this->assertCount(1, $openEngagements);
        $this->assertSame($engagement->id, $openEngagements->first()->id);
        $this->assertSame(WorkerEngagementStatus::Accepted, $openEngagements->first()->status);
        $response
            ->assertSee('العقود المفتوحة')
            ->assertSee('Accepted Professional')
            ->assertSee('مقبول')
            ->assertSee('عرض تفاصيل التعاقد');
    }

    public function test_discovery_offer_reuses_phase_2b_and_ignores_a_client_worker_id(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $discoveredProfile] = $this->createWorker('Discovered Worker');
        [, $otherProfile] = $this->createWorker('Other Worker');

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-discovery.engagements.store', $discoveredProfile->id), [
                'worker_profile_id' => $otherProfile->id,
                'job_title' => 'Discovered Role',
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($discoveredProfile->id, $engagement->worker_profile_id);
        $this->assertSame(
            WorkerEngagementStatus::Offered,
            $engagement->status,
        );
        $this->assertDatabaseCount('worker_engagements', 1);
    }

    public function test_discovery_does_not_create_a_duplicate_open_engagement(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createWorker('Already Offered Worker');
        $this->createOffer($owner, $workspace, $profile);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-discovery.engagements.store', $profile->id), [
                'job_title' => 'Duplicate Role',
            ])
            ->assertSessionHasErrors('discovery');

        $this->assertDatabaseCount('worker_engagements', 1);
    }

    public function test_active_company_members_can_discover_but_only_managers_can_start_an_offer(): void
    {
        [$owner, $workspace] = $this->createCompany();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        [, $profile] = $this->createWorker('Member Visible Worker');

        $this->companyRequest($member, $workspace)
            ->get(route('workspace.worker-discovery.index'))
            ->assertOk()
            ->assertSee($profile->display_name);

        $this->companyRequest($member, $workspace)
            ->post(route('workspace.worker-discovery.engagements.store', $profile->id), [
                'job_title' => 'Unauthorized Role',
            ])
            ->assertForbidden();
    }

    public function test_worker_can_enable_and_disable_discovery_visibility(): void
    {
        [$worker, $profile] = $this->createWorker('Opt In Worker', discoverable: false);

        $this->actingAs($worker)
            ->patch(route('worker.profile.update'), [
                'display_name' => $profile->display_name,
                'country_code' => $profile->country_code,
                'nationality' => 'Jordanian',
                'professional_summary' => $profile->professional_summary,
                'sales_experience_level' => $profile->sales_experience_level,
                'availability_status' => $profile->availability_status,
                'is_discoverable' => true,
                'legal_name' => 'Legal Worker',
                'date_of_birth' => '1990-01-01',
                'identity_number' => 'PRIVATE-OPT-IN-ID',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_discovery_settings', [
            'worker_profile_id' => $profile->id,
            'is_discoverable' => true,
        ]);

        $this->actingAs($worker)
            ->patch(route('worker.profile.update'), [
                'display_name' => $profile->display_name,
                'country_code' => $profile->country_code,
                'nationality' => 'Jordanian',
                'professional_summary' => $profile->professional_summary,
                'sales_experience_level' => $profile->sales_experience_level,
                'availability_status' => $profile->availability_status,
                'is_discoverable' => false,
                'legal_name' => 'Legal Worker',
                'date_of_birth' => '1990-01-01',
                'identity_number' => 'PRIVATE-OPT-IN-ID',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_discovery_settings', [
            'worker_profile_id' => $profile->id,
            'is_discoverable' => false,
        ]);
    }

    public function test_discovery_results_are_paginated_with_a_bounded_page_size(): void
    {
        [$owner, $workspace] = $this->createCompany();

        for ($index = 1; $index <= 25; $index++) {
            $this->createWorker('Paginated Worker '.$index);
        }

        $response = $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index', ['per_page' => 24]))
            ->assertOk();

        $paginator = $response->viewData('workers');

        $this->assertSame(24, $paginator->perPage());
        $this->assertSame(25, $paginator->total());
        $this->assertSame(2, $paginator->lastPage());
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Discovery Workspace'): array
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
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(
        string $name,
        array $overrides = [],
        bool $discoverable = true,
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
        ], $overrides));
        $profile->discoverySettings()->create([
            'is_discoverable' => $discoverable,
        ]);

        return [$user, $profile];
    }

    private function createOffer(
        User $owner,
        Workspace $workspace,
        WorkerProfile $profile,
    ): WorkerEngagement {
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'job_title' => 'Existing Role',
            ])
            ->assertRedirect();

        return WorkerEngagement::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
