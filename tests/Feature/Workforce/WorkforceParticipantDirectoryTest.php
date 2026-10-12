<?php

declare(strict_types=1);

namespace Tests\Feature\Workforce;

use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\Models\AiEmployee;
use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkforceParticipantType;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Workforce\WorkforceParticipant;
use App\Services\Workforce\WorkforceParticipantDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkforceParticipantDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_returns_explicit_types_and_allowlisted_fields(): void
    {
        [$owner, $workspace] = $this->createCompany('Coexistence Workspace');
        [, $profile] = $this->createWorker('Independent Human');
        $engagement = $this->createEngagement($workspace, $owner, $profile);
        $aiEmployee = $this->createAiEmployee($workspace, 'AI Support');

        $profile->privateIdentity()->create([
            'legal_name' => 'Private Legal Identity',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'PRIVATE-IDENTITY-123',
        ]);

        $participants = $this->inWorkspace(
            $workspace,
            fn () => $this->directory()->forWorkspace($owner, $workspace),
        );

        $this->assertSame(
            [
                WorkforceParticipantType::CompanyStaff,
                WorkforceParticipantType::IndependentWorker,
                WorkforceParticipantType::AiEmployee,
            ],
            $participants->map(fn (WorkforceParticipant $participant): WorkforceParticipantType => $participant->type)->all(),
        );

        $worker = $participants->first(
            fn (WorkforceParticipant $participant): bool => $participant->type === WorkforceParticipantType::IndependentWorker,
        );
        $ai = $participants->first(
            fn (WorkforceParticipant $participant): bool => $participant->type === WorkforceParticipantType::AiEmployee,
        );

        $this->assertInstanceOf(WorkforceParticipant::class, $worker);
        $this->assertSame($engagement->id, $worker->engagementId);
        $this->assertSame($profile->id, $worker->workerProfileId);
        $this->assertSame($engagement->id, $worker->sourceId);
        $this->assertSame(WorkerEngagementStatus::Active->value, $worker->status);
        $this->assertSame(WorkforceParticipant::ALLOWED_KEYS, array_keys($worker->toArray()));
        $this->assertArrayNotHasKey('email', $worker->toArray());
        $this->assertArrayNotHasKey('private_identity', $worker->toArray());
        $this->assertArrayNotHasKey('verification_documents', $worker->toArray());

        $this->assertInstanceOf(WorkforceParticipant::class, $ai);
        $this->assertSame($aiEmployee->id, $ai->aiEmployeeId);
        $this->assertSame('active', $ai->status);
        $this->assertArrayNotHasKey('instructions', $ai->toArray());
        $this->assertArrayNotHasKey('tool_permissions', $ai->toArray());
    }

    public function test_resolution_uses_explicit_source_type_and_fails_closed_across_workspaces(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [, $profileB] = $this->createWorker('Shared Name');
        $engagementB = $this->createEngagement($workspaceB, $ownerB, $profileB);
        $aiEmployeeB = $this->createAiEmployee($workspaceB, 'Shared Name');
        $staffB = WorkspaceUser::query()
            ->where('workspace_id', $workspaceB->id)
            ->where('user_id', $ownerB->id)
            ->firstOrFail();

        $resolved = $this->inWorkspace($workspaceA, function () use (
            $ownerA,
            $workspaceA,
            $engagementB,
            $aiEmployeeB,
            $staffB,
        ): array {
            return [
                $this->directory()->resolve(
                    $ownerA,
                    $workspaceA,
                    WorkforceParticipantType::IndependentWorker,
                    (int) $engagementB->id,
                ),
                $this->directory()->resolve(
                    $ownerA,
                    $workspaceA,
                    WorkforceParticipantType::AiEmployee,
                    (int) $aiEmployeeB->id,
                ),
                $this->directory()->resolve(
                    $ownerA,
                    $workspaceA,
                    WorkforceParticipantType::CompanyStaff,
                    (int) $staffB->id,
                ),
            ];
        });

        $this->assertSame([null, null, null], $resolved);
    }

    public function test_invalid_participant_references_return_no_participant(): void
    {
        [$owner, $workspace] = $this->createCompany();

        $resolved = $this->inWorkspace($workspace, function () use ($owner, $workspace): array {
            return array_map(
                fn (WorkforceParticipantType $type): ?WorkforceParticipant => $this->directory()->resolve(
                    $owner,
                    $workspace,
                    $type,
                    999999,
                ),
                WorkforceParticipantType::cases(),
            );
        });

        $this->assertSame([null, null, null], $resolved);
    }

    public function test_employee_page_distinguishes_types_and_excludes_private_worker_and_ai_configuration(): void
    {
        [$owner, $workspace] = $this->createCompany('Coexistence UI');
        [, $profile] = $this->createWorker('Independent Human');
        $this->createEngagement($workspace, $owner, $profile);
        $profile->privateIdentity()->create([
            'legal_name' => 'Private Legal Identity',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'PRIVATE-IDENTITY-123',
        ]);
        $this->createAiEmployee($workspace, 'AI Operator', 'PRIVATE-AI-INSTRUCTIONS');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.employees.index'))
            ->assertOk()
            ->assertSee('موظف الشركة')
            ->assertSee('عامل مستقل')
            ->assertSee('موظف AI')
            ->assertSee('Independent Human')
            ->assertSee('AI Operator')
            ->assertDontSee('Private Legal Identity')
            ->assertDontSee('PRIVATE-IDENTITY-123')
            ->assertDontSee('PRIVATE-AI-INSTRUCTIONS');
    }

    public function test_non_manager_cannot_read_the_existing_employee_area_or_participant_directory(): void
    {
        [, $workspace] = $this->createCompany();
        $member = User::factory()->create(['name' => 'Regular Member']);
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.employees.index'))
            ->assertForbidden();

        $this->inWorkspace($workspace, function () use ($member, $workspace): void {
            $this->expectException(AuthorizationException::class);
            $this->directory()->forWorkspace($member, $workspace);
        });
    }

    private function directory(): WorkforceParticipantDirectory
    {
        return app(WorkforceParticipantDirectory::class);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Test Workspace'): array
    {
        $owner = User::factory()->create(['name' => $name.' Owner']);
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
    private function createWorker(string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent professional.',
            'sales_experience_level' => 'senior',
        ]);

        return [$user, $profile];
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
            'job_title' => 'Independent Specialist',
            'created_by' => $owner->id,
            'active_at' => now(),
        ]);
    }

    private function createAiEmployee(
        Workspace $workspace,
        string $name,
        string $instructions = 'Safe professional instructions',
    ): AiEmployee {
        $draft = app(EmployeeFactory::class)->fromArray([
            'name' => $name,
            'role' => 'customer_support',
            'instructions' => $instructions,
            'persona' => ['language' => 'ar', 'dialect' => 'saudi'],
            'skills' => ['customer_support'],
        ]);

        return app(EmployeeService::class)->create($workspace, $draft);
    }
}
