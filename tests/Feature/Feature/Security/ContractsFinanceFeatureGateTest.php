<?php

namespace Tests\Feature\Feature\Security;

use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Contracts\ContractService;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Authorization\FinanceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContractsFinanceFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_finance_enabled_owner_can_use_legacy_and_finance_contract_routes(): void
    {
        [$owner, $workspace, $customer] = $this->bootWorkspace(financeEnabled: true);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.contracts.index'))
            ->assertOk();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.contracts.store'), $this->storePayload($customer))
            ->assertRedirect();

        $contract = Contract::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('title', 'Finance Gate Contract')
            ->firstOrFail();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.contracts.show', $contract))
            ->assertOk();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.finance.contracts.update', $contract), [
                'title' => 'Updated Finance Gate Contract',
                'customer_id' => $customer->id,
                'currency' => 'SAR',
                'items' => [[
                    'title' => 'Line',
                    'quantity' => 1,
                    'unit_price' => 500,
                ]],
            ])
            ->assertRedirect();

        $this->assertSame('Updated Finance Gate Contract', $contract->fresh()->title);
    }

    public function test_finance_disabled_blocks_legacy_contract_mutations_without_db_changes(): void
    {
        [$owner, $workspace, $customer] = $this->bootWorkspace(financeEnabled: false);
        $contract = $this->seedExistingContract($workspace, $owner, $customer);
        $beforeContracts = Contract::withoutGlobalScopes()->count();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.contracts.store'), $this->storePayload($customer))
            ->assertRedirect(route('workspace.subscriptions.index'))
            ->assertSessionHas('upgrade_required.feature', 'finance');

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.contracts.update', $contract), [
                'title' => 'Blocked Update',
                'customer_id' => $customer->id,
                'currency' => 'SAR',
            ])
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.contracts.activate', $contract))
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('workspace.contracts.destroy', $contract))
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->assertSame($beforeContracts, Contract::withoutGlobalScopes()->count());
        $this->assertSame('Existing Contract', $contract->fresh()->title);
        $this->assertSame('draft', $contract->fresh()->status);
    }

    public function test_finance_disabled_blocks_legacy_contract_reads_and_finance_prefixed_routes(): void
    {
        [$owner, $workspace, $customer] = $this->bootWorkspace(financeEnabled: false);
        $contract = $this->seedExistingContract($workspace, $owner, $customer);

        foreach ([
            route('workspace.contracts.index'),
            route('workspace.contracts.create'),
            route('workspace.contracts.show', $contract),
            route('workspace.finance.contracts.index'),
            route('workspace.finance.contracts.show', $contract),
        ] as $uri) {
            $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
                ->get($uri)
                ->assertRedirect(route('workspace.subscriptions.index'))
                ->assertSessionHas('upgrade_required.feature', 'finance');
        }
    }

    public function test_finance_disabled_blocks_contract_mutations_even_when_user_has_manage_permission(): void
    {
        [$owner, $workspace, $customer] = $this->bootWorkspace(financeEnabled: false);
        $staff = $this->attachStaff($workspace, ['contracts.manage', 'contracts.view', 'finance.view']);
        $contract = $this->seedExistingContract($workspace, $owner, $customer);

        $this->assertTrue(app(FinanceAccess::class)->can($staff, $workspace, 'contracts.manage'));

        $this->actingAs($staff)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.contracts.store'), $this->storePayload($customer))
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->actingAs($staff)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.activate', $contract))
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->assertSame(1, Contract::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
        $this->assertSame('draft', $contract->fresh()->status);
    }

    public function test_finance_enabled_staff_without_manage_permission_is_still_blocked_by_authorization(): void
    {
        [$owner, $workspace, $customer] = $this->bootWorkspace(financeEnabled: true);
        $staff = $this->attachStaff($workspace, ['contracts.view', 'finance.view']);
        $contract = $this->seedExistingContract($workspace, $owner, $customer);
        $before = Contract::withoutGlobalScopes()->count();

        $this->actingAs($staff)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.contracts.store'), $this->storePayload($customer))
            ->assertForbidden();

        $this->actingAs($staff)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.contracts.activate', $contract))
            ->assertForbidden();

        $this->assertSame($before, Contract::withoutGlobalScopes()->count());
    }

    public function test_non_member_is_rejected_before_contract_authorization(): void
    {
        [$owner, $workspace, $customer] = $this->bootWorkspace(financeEnabled: true);
        $contract = $this->seedExistingContract($workspace, $owner, $customer);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.contracts.index'))
            ->assertRedirect(route('workspace.choose'));

        $this->actingAs($outsider)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.contracts.store'), $this->storePayload($customer))
            ->assertRedirect(route('workspace.choose'));

        $this->actingAs($outsider)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.contracts.show', $contract))
            ->assertNotFound();

        $this->assertSame(1, Contract::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());
    }

    public function test_cross_workspace_contract_access_remains_not_found(): void
    {
        [$owner, $workspace, $customer] = $this->bootWorkspace(financeEnabled: true);
        [$stranger] = $this->bootWorkspace(financeEnabled: true);
        $contract = $this->seedExistingContract($workspace, $owner, $customer);

        $this->actingAs($stranger)->withSession(['current_workspace_id' => $this->otherWorkspaceId($stranger)])
            ->get(route('workspace.contracts.show', $contract))
            ->assertNotFound();

        $this->actingAs($stranger)->withSession(['current_workspace_id' => $this->otherWorkspaceId($stranger)])
            ->post(route('workspace.contracts.activate', $contract))
            ->assertNotFound();
    }

    public function test_finance_disabled_blocks_contract_attachment_mutations(): void
    {
        Storage::fake('local');
        [$owner, $workspace, $customer] = $this->bootWorkspace(financeEnabled: false);
        $contract = $this->seedExistingContract($workspace, $owner, $customer);
        $before = ContractAttachment::withoutGlobalScopes()->count();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.contracts.attachments.store', $contract), [
                'attachments' => [UploadedFile::fake()->create('blocked.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.attachments.store', $contract), [
                'attachments' => [UploadedFile::fake()->create('blocked-finance.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->assertSame($before, ContractAttachment::withoutGlobalScopes()->count());
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Customer}
     */
    private function bootWorkspace(bool $financeEnabled): array
    {
        app(WorkspaceContext::class)->clear();
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['finance', 'products', 'orders', 'customers'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                [
                    'workspace_id' => $workspace->id,
                    'feature_key' => $feature,
                    'enabled' => $feature === 'finance' ? $financeEnabled : true,
                    'source' => 'manual',
                ]
            );
        }

        $plan = Plan::query()->where('is_active', true)->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        if ($financeEnabled) {
            $this->inWorkspace($workspace, function () use ($workspace): void {
                app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
                app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
            });
        }

        app(WorkspaceContext::class)->clear();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Contract Customer',
            'phone' => '0501234567',
        ]);

        return [$user, $workspace, $customer];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function attachStaff(Workspace $workspace, array $permissions): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function seedExistingContract(Workspace $workspace, User $owner, Customer $customer): Contract
    {
        return $this->inWorkspace($workspace, function () use ($workspace, $owner, $customer): Contract {
            return app(ContractService::class)->create($workspace, [
                'title' => 'Existing Contract',
                'customer_id' => $customer->id,
                'currency' => 'SAR',
                'value' => 1000,
            ], (int) $owner->id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Customer $customer): array
    {
        return [
            'title' => 'Finance Gate Contract',
            'customer_id' => $customer->id,
            'currency' => 'SAR',
            'items' => [[
                'title' => 'Service Line',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ];
    }

    private function otherWorkspaceId(User $user): int
    {
        return (int) $user->workspaces()->value('workspaces.id');
    }
}
