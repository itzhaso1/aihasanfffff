<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkforceCapabilityDefinition;
use App\Models\Workspace;
use App\Services\Worker\WorkerEngagementAuthorization;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerEngagementCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_capability_catalog_is_seeded_with_stable_keys_and_is_idempotent(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        $this->seed(WorkerCapabilitySeeder::class);

        $this->assertDatabaseHas('workforce_capability_definitions', [
            'capability_key' => 'customers.phone.view',
            'is_active' => true,
        ]);
        $this->assertSame(
            count(config('worker_capabilities')),
            WorkforceCapabilityDefinition::query()->count(),
        );
    }

    public function test_duplicate_capability_keys_are_rejected_by_the_unique_constraint(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        $definition = WorkforceCapabilityDefinition::query()
            ->where('capability_key', 'customers.view')
            ->firstOrFail();

        $this->expectException(QueryException::class);
        WorkforceCapabilityDefinition::query()->create([
            'capability_key' => $definition->capability_key,
            'display_name' => 'Duplicate capability',
            'description' => 'Should not be persisted.',
        ]);
    }

    public function test_authorized_company_user_can_grant_a_capability_to_an_active_engagement(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $response = $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                'capability_key' => 'customers.phone.view',
            ]);

        $response->assertRedirect(route(
            'workspace.worker-engagements.capabilities.index',
            $engagement,
            absolute: false,
        ));
        $this->assertDatabaseHas('worker_engagement_permissions', [
            'worker_engagement_id' => $engagement->id,
            'capability_key' => 'customers.phone.view',
            'allowed' => true,
        ]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $worker->id]);
        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.capabilities.index', $engagement))
            ->assertOk()
            ->assertSee('customers.phone.view')
            ->assertSee('عرض أرقام هواتف العملاء')
            ->assertSee('عرض أرقام هواتف العملاء عند منح هذه الصلاحية.')
            ->assertDontSee('View customer phone numbers');
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.engagement_capability.granted',
            'entity_type' => WorkerEngagementPermission::class,
        ]);
    }

    public function test_duplicate_grants_update_one_permission_row(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $payload = ['capability_key' => 'customers.view'];
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), $payload)
            ->assertRedirect();
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), $payload)
            ->assertRedirect();

        $this->assertSame(
            1,
            WorkerEngagementPermission::query()
                ->where('worker_engagement_id', $engagement->id)
                ->where('capability_key', 'customers.view')
                ->count(),
        );
    }

    public function test_workspace_member_without_management_access_cannot_grant_or_revoke_capabilities(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->companyRequest($member, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                'capability_key' => 'customers.view',
            ])
            ->assertForbidden();

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                'capability_key' => 'customers.view',
            ]);
        $this->companyRequest($member, $workspace)
            ->delete(route(
                'workspace.worker-engagements.capabilities.destroy',
                [$engagement, 'customers.view'],
            ))
            ->assertForbidden();
    }

    public function test_invalid_capability_key_is_rejected_by_form_request_validation(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $response = $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                'capability_key' => 'Arabic Display Label',
            ]);

        $response->assertSessionHasErrors('capability_key');
        $this->assertDatabaseMissing('worker_engagement_permissions', [
            'worker_engagement_id' => $engagement->id,
        ]);
    }

    public function test_authorized_company_user_can_revoke_a_capability(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                'capability_key' => 'customers.email.view',
            ]);
        $response = $this->companyRequest($owner, $workspace)
            ->delete(route(
                'workspace.worker-engagements.capabilities.destroy',
                [$engagement, 'customers.email.view'],
            ));

        $response->assertRedirect();
        $this->assertDatabaseHas('worker_engagement_permissions', [
            'worker_engagement_id' => $engagement->id,
            'capability_key' => 'customers.email.view',
            'allowed' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'worker.engagement_capability.revoked',
            'entity_type' => WorkerEngagementPermission::class,
        ]);
        $this->assertFalse(app(WorkerEngagementAuthorization::class)->workerCan(
            $worker,
            $workspace,
            $engagement,
            'customers.email.view',
        ));
    }

    public function test_worker_authorization_requires_ownership_active_status_and_granted_capability(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [, $otherProfile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $authorization = app(WorkerEngagementAuthorization::class);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                'capability_key' => 'customers.view',
            ]);

        $this->assertTrue($authorization->workerCan(
            $worker,
            $workspace,
            $engagement,
            'customers.view',
        ));
        $this->assertFalse($authorization->workerCan(
            $worker,
            $workspace,
            $engagement,
            'customers.phone.view',
        ));
        $otherWorker = User::query()->findOrFail($otherProfile->user_id);
        $this->assertFalse($authorization->workerCan(
            $otherWorker,
            $workspace,
            $engagement,
            'customers.view',
        ));
    }

    public function test_granted_capability_is_denied_when_engagement_is_not_active(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $engagement->id));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagement), [
                'capability_key' => 'customers.view',
            ]);

        $this->assertFalse(app(WorkerEngagementAuthorization::class)->workerCan(
            $worker,
            $workspace,
            $engagement,
            'customers.view',
        ));
        $this->assertSame(
            WorkerEngagementStatus::Accepted,
            WorkerEngagement::withoutGlobalScopes()->findOrFail($engagement->id)->status,
        );
    }

    public function test_capabilities_are_isolated_between_two_engagements_for_the_same_worker(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $worker, $profile);
        $engagementB = $this->createActiveEngagement($ownerB, $workspaceB, $worker, $profile);

        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagementA), [
                'capability_key' => 'customers.phone.view',
            ]);
        $this->companyRequest($ownerB, $workspaceB)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagementB), [
                'capability_key' => 'customers.email.view',
            ]);

        $authorization = app(WorkerEngagementAuthorization::class);
        $this->assertTrue($authorization->workerCan($worker, $workspaceA, $engagementA, 'customers.phone.view'));
        $this->assertFalse($authorization->workerCan($worker, $workspaceA, $engagementA, 'customers.email.view'));
        $this->assertTrue($authorization->workerCan($worker, $workspaceB, $engagementB, 'customers.email.view'));
        $this->assertFalse($authorization->workerCan($worker, $workspaceB, $engagementB, 'customers.phone.view'));
    }

    public function test_workspace_isolation_prevents_viewing_or_modifying_another_workspace_grants(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagementA = $this->createActiveEngagement($ownerA, $workspaceA, $worker, $profile);

        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagementA), [
                'capability_key' => 'customers.view',
            ]);

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-engagements.capabilities.index', $engagementA))
            ->assertNotFound();
        $this->companyRequest($ownerB, $workspaceB)
            ->post(route('workspace.worker-engagements.capabilities.store', $engagementA), [
                'capability_key' => 'customers.phone.view',
            ])
            ->assertNotFound();
        $this->companyRequest($ownerB, $workspaceB)
            ->delete(route(
                'workspace.worker-engagements.capabilities.destroy',
                [$engagementA, 'customers.view'],
            ))
            ->assertNotFound();
    }

    public function test_mobile_company_manager_can_list_grant_and_revoke_engagement_capabilities(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);
        $headers = ['X-Workspace-Id' => (string) $workspace->id];

        Sanctum::actingAs($owner);
        $initial = $this->withHeaders($headers)
            ->getJson(route('mobile.v1.company.worker-engagements.capabilities.index', $engagement))
            ->assertOk()
            ->assertJsonPath('data.engagement_id', $engagement->id)
            ->json('data.capabilities');

        $initialCustomersCapability = collect($initial)
            ->firstWhere('capability_key', 'customers.view');
        $this->assertFalse($initialCustomersCapability['allowed']);

        $grantResponse = $this->withHeaders($headers)
            ->postJson(
                route('mobile.v1.company.worker-engagements.capabilities.store', $engagement),
                ['capability_key' => 'customers.view'],
            )
            ->assertOk();

        $grantedCapability = collect(
            $grantResponse->json('data.capabilities'),
        )->firstWhere('capability_key', 'customers.view');
        $this->assertIsArray($grantedCapability);
        $this->assertTrue($grantedCapability['allowed']);

        $this->assertDatabaseHas('worker_engagement_permissions', [
            'worker_engagement_id' => $engagement->id,
            'capability_key' => 'customers.view',
            'allowed' => true,
        ]);

        $revokeResponse = $this->withHeaders($headers)
            ->deleteJson(route(
                'mobile.v1.company.worker-engagements.capabilities.destroy',
                [$engagement, 'customers.view'],
            ))
            ->assertOk();

        $revokedCapability = collect(
            $revokeResponse->json('data.capabilities'),
        )->firstWhere('capability_key', 'customers.view');
        $this->assertIsArray($revokedCapability);
        $this->assertFalse($revokedCapability['allowed']);

        $this->assertDatabaseHas('worker_engagement_permissions', [
            'worker_engagement_id' => $engagement->id,
            'capability_key' => 'customers.view',
            'allowed' => false,
        ]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $worker->id]);
    }

    public function test_mobile_capability_management_forbids_non_manager_members(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        Sanctum::actingAs($member);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson(route('mobile.v1.company.worker-engagements.capabilities.index', $engagement))
            ->assertForbidden();
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson(
                route('mobile.v1.company.worker-engagements.capabilities.store', $engagement),
                ['capability_key' => 'customers.view'],
            )
            ->assertForbidden();
    }

    public function test_mobile_capability_management_isolated_by_workspace_and_worker_membership(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($ownerA, $workspaceA, $worker, $profile);

        Sanctum::actingAs($ownerB);
        $this->withHeader('X-Workspace-Id', (string) $workspaceB->id)
            ->getJson(route('mobile.v1.company.worker-engagements.capabilities.index', $engagement))
            ->assertNotFound();

        Sanctum::actingAs($worker);
        $workerReadResponse = $this->withHeader(
            'X-Workspace-Id',
            (string) $workspaceA->id,
        )->getJson(
            route('mobile.v1.company.worker-engagements.capabilities.index', $engagement),
        );
        $this->assertContains($workerReadResponse->status(), [403, 404]);
        $workerMutationResponse = $this->withHeader(
            'X-Workspace-Id',
            (string) $workspaceA->id,
        )->postJson(
            route('mobile.v1.company.worker-engagements.capabilities.store', $engagement),
            ['capability_key' => 'customers.view'],
        );
        $this->assertContains($workerMutationResponse->status(), [403, 404]);

        $this->assertDatabaseMissing('workspace_users', ['user_id' => $worker->id]);
        $this->assertDatabaseMissing('worker_engagement_permissions', [
            'worker_engagement_id' => $engagement->id,
            'capability_key' => 'customers.view',
            'allowed' => true,
        ]);
    }

    public function test_mobile_capability_management_reuses_capability_validation(): void
    {
        $this->seed(WorkerCapabilitySeeder::class);
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createActiveEngagement($owner, $workspace, $worker, $profile);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->postJson(
                route('mobile.v1.company.worker-engagements.capabilities.store', $engagement),
                ['capability_key' => 'Arabic Display Label'],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capability_key']);
    }

    private function createActiveEngagement(
        User $owner,
        Workspace $workspace,
        User $worker,
        WorkerProfile $profile,
    ): WorkerEngagement {
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $engagement->id))
            ->assertRedirect();
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
                'job_title' => 'Capability Test Worker',
            ])
            ->assertRedirect();

        return WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Capability Workspace'): array
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

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
