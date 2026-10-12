<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Models\User;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Models\Workspace;
use Database\Seeders\WorkerSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerProfessionalTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerSkillSeeder::class);
    }

    public function test_worker_can_maintain_skills_languages_and_experience_for_their_own_profile(): void
    {
        [$worker, $profile] = $this->createWorker('Catalog Worker');
        $other = $this->createWorker('Other Worker')[1];
        $skill = WorkerSkill::query()->where('skill_key', 'sales')->firstOrFail();

        $this->actingAs($worker)
            ->put(route('worker.skills.sync'), ['skill_ids' => [$skill->id]])
            ->assertRedirect();
        $this->actingAs($worker)
            ->put(route('worker.languages.sync'), ['language_codes' => ['ar']])
            ->assertRedirect();
        $this->actingAs($worker)
            ->post(route('worker.experiences.store'), [
                'title' => 'Sales Specialist',
                'organization_name' => 'North Retail',
                'started_on' => '2024-01-01',
                'is_current' => true,
                'description' => 'Handled retail conversations.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_profile_skills', [
            'worker_profile_id' => $profile->id,
            'worker_skill_id' => $skill->id,
        ]);
        $this->assertDatabaseMissing('worker_profile_skills', [
            'worker_profile_id' => $other->id,
        ]);
        $this->assertDatabaseHas('worker_profile_languages', [
            'worker_profile_id' => $profile->id,
            'language_code' => 'ar',
        ]);
        $this->assertDatabaseHas('worker_work_experiences', [
            'worker_profile_id' => $profile->id,
            'title' => 'Sales Specialist',
            'is_current' => true,
        ]);
    }

    public function test_company_discovery_uses_professional_data_and_excludes_private_identity(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createWorker('Discoverable Specialist');
        $profile->privateIdentity()->create([
            'legal_name' => 'Hidden Legal Name',
            'date_of_birth' => '1991-02-02',
            'identity_number' => 'SECRET-ID-77',
        ]);
        $skill = WorkerSkill::query()->where('skill_key', 'sales')->firstOrFail();
        $this->actingAs($worker)->put(route('worker.skills.sync'), ['skill_ids' => [$skill->id]]);
        $this->actingAs($worker)->put(route('worker.languages.sync'), ['language_codes' => ['en']]);
        $this->actingAs($worker)->post(route('worker.experiences.store'), [
            'title' => 'Account Lead',
            'organization_name' => 'Public Org',
            'started_on' => '2023-05-01',
            'ended_on' => '2024-05-01',
            'is_current' => false,
        ]);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index'))
            ->assertOk()
            ->assertSee('name="skill_key"', false)
            ->assertSee('value="sales"', false)
            ->assertSee('المبيعات')
            ->assertSee(route('workspace.worker-engagements.index'), false)
            ->assertSee(route('workspace.worker-disputes.index'), false);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.index', ['skill_key' => 'sales']))
            ->assertOk()
            ->assertSee('Discoverable Specialist')
            ->assertDontSee('Hidden Legal Name')
            ->assertDontSee('SECRET-ID-77');

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-discovery.show', $profile))
            ->assertOk()
            ->assertSee('المبيعات')
            ->assertSee('en')
            ->assertSee('Account Lead')
            ->assertDontSee('SECRET-ID-77');
    }

    public function test_worker_can_update_an_existing_experience_from_the_profile_form(): void
    {
        [$worker, $profile] = $this->createWorker('Experience Editor');
        $this->actingAs($worker)->post(route('worker.experiences.store'), [
            'title' => 'Original Role',
            'organization_name' => 'Original Org',
            'started_on' => '2021-04-01',
            'is_current' => true,
        ])->assertRedirect();
        $experienceId = (int) $profile->experiences()->value('id');

        $this->actingAs($worker)
            ->get(route('worker.profile.edit'))
            ->assertOk()
            ->assertSee(route('worker.experiences.update', $experienceId), false);

        $this->actingAs($worker)
            ->patch(route('worker.experiences.update', $experienceId), [
                'experience_id' => $experienceId,
                'title' => 'Updated Role',
                'organization_name' => 'Original Org',
                'started_on' => '2021-04-01',
                'is_current' => true,
                'description' => 'Updated description',
            ])
            ->assertRedirect();

        $this->assertSame(1, $profile->experiences()->count());
        $this->assertDatabaseHas('worker_work_experiences', [
            'id' => $experienceId,
            'worker_profile_id' => $profile->id,
            'title' => 'Updated Role',
            'description' => 'Updated description',
        ]);
    }

    public function test_worker_cannot_edit_another_workers_experience(): void
    {
        [$owner] = $this->createWorker('Owner Worker');
        [$other] = $this->createWorker('Intruder Worker');
        $this->actingAs($owner)->post(route('worker.experiences.store'), [
            'title' => 'Private Role',
            'organization_name' => 'Owned Org',
            'started_on' => '2022-01-01',
            'is_current' => true,
        ]);
        $experienceId = (int) $owner->workerProfile->experiences()->value('id');

        $this->actingAs($other)
            ->patch(route('worker.experiences.update', $experienceId), [
                'title' => 'Taken Over',
                'organization_name' => 'Owned Org',
                'started_on' => '2022-01-01',
                'is_current' => true,
            ])
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(string $name, array $overrides = []): array
    {
        $worker = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create(array_merge([
            'user_id' => $worker->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Professional summary.',
            'sales_experience_level' => 'senior',
        ], $overrides));
        $profile->discoverySettings()->create(['is_discoverable' => true]);

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
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }

    private function companyRequest(User $owner, Workspace $workspace): static
    {
        return $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
