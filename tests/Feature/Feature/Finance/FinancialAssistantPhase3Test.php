<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Contracts\ContractService;
use App\Services\Finance\Assistant\FinanceAssistantToolExecutor;
use App\Services\Finance\Assistant\FinanceAssistantToolRegistry;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\BillingScheduleService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancialAssistantPhase3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance_assistant.planner' => 'heuristic']);
        RateLimiter::clear('mobile-ai');
        $this->seed(FoundationSeeder::class);
    }

    public function test_contracts_lookup_finds_contract_by_number(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract] = $this->openContractWithSchedule($workspace, $owner, 500);

        $result = $this->executeTool($workspace, $owner, 'contracts.lookup', [
            'contract_number' => $contract->contract_number,
        ]);

        $this->assertTrue($result->available);
        $this->assertSame('found', $result->data['status'] ?? null);
        $this->assertSame($contract->contract_number, $result->data['contract']['contract_number'] ?? null);
    }

    public function test_contracts_lookup_finds_contract_by_customer_name(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Mohammed Contract Client');
        $this->openContractWithSchedule($workspace, $owner, 400, customer: $customer);

        $result = $this->executeTool($workspace, $owner, 'contracts.lookup', [
            'customer_name' => 'Mohammed',
        ]);

        $this->assertTrue($result->available);
        $this->assertSame('found', $result->data['status'] ?? null);
        $this->assertStringContainsString('Mohammed', (string) ($result->data['contract']['customer_name'] ?? ''));
    }

    public function test_contracts_balance_reflects_partial_payment(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract, $schedule] = $this->openContractWithSchedule($workspace, $owner, 200);

        $this->runInWorkspace($workspace, function () use ($schedule, $owner): void {
            $invoice = app(BillingScheduleService::class)->createInvoiceFromSchedule($schedule, (int) $owner->id, true);
            app(InvoiceService::class)->issue($invoice, (int) $owner->id);
            app(InvoicePaymentService::class)->recordPayment($invoice->fresh(), [
                'amount' => 50,
                'method' => 'cash',
                'payment_date' => now()->toDateString(),
            ], (int) $owner->id);
        });

        $result = $this->executeTool($workspace, $owner, 'contracts.balance', [
            'contract_number' => $contract->contract_number,
        ]);

        $this->assertTrue($result->available);
        $this->assertSame('50.00', $result->data['paid'] ?? null);
        $this->assertGreaterThan(0, (float) ($result->data['outstanding'] ?? 0));
    }

    public function test_contracts_unbilled_returns_remaining_amount(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract, $schedule] = $this->openContractWithSchedule($workspace, $owner, 1000, occurrences: 2);

        $this->runInWorkspace($workspace, function () use ($schedule, $owner): void {
            $invoice = app(BillingScheduleService::class)->createInvoiceFromSchedule($schedule, (int) $owner->id, true);
            app(InvoiceService::class)->issue($invoice, (int) $owner->id);
        });

        $result = $this->executeTool($workspace, $owner, 'contracts.unbilled', [
            'contract_number' => $contract->contract_number,
        ]);

        $this->assertTrue($result->available);
        $this->assertGreaterThan(0, (float) ($result->data['value'] ?? 0));
    }

    public function test_contracts_invoices_lists_linked_invoices(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract, $schedule] = $this->openContractWithSchedule($workspace, $owner, 300);

        $this->runInWorkspace($workspace, function () use ($schedule, $owner): void {
            app(BillingScheduleService::class)->createInvoiceFromSchedule($schedule, (int) $owner->id, true);
        });

        $result = $this->executeTool($workspace, $owner, 'contracts.invoices', [
            'contract_number' => $contract->contract_number,
        ]);

        $this->assertTrue($result->available);
        $this->assertGreaterThanOrEqual(1, (int) ($result->data['count'] ?? 0));
    }

    public function test_contracts_schedules_lists_billing_schedules(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract] = $this->openContractWithSchedule($workspace, $owner, 250);

        $result = $this->executeTool($workspace, $owner, 'contracts.schedules', [
            'contract_number' => $contract->contract_number,
        ]);

        $this->assertTrue($result->available);
        $this->assertGreaterThanOrEqual(1, (int) ($result->data['count'] ?? 0));
    }

    public function test_billing_schedules_upcoming_includes_active_schedule(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->openContractWithSchedule($workspace, $owner, 180);

        $result = $this->executeTool($workspace, $owner, 'billing_schedules.upcoming', [
            'days' => 30,
            'limit' => 5,
        ]);

        $this->assertTrue($result->available);
        $this->assertGreaterThanOrEqual(1, (int) ($result->data['count'] ?? 0));
    }

    public function test_contract_lookup_is_scoped_to_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('Contract A');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Contract B');
        [$contract] = $this->openContractWithSchedule($workspaceA, $ownerA, 100);

        $result = $this->executeTool($workspaceB, $ownerB, 'contracts.lookup', [
            'contract_number' => $contract->contract_number,
        ]);

        $this->assertFalse($result->available);
        $this->assertSame('not_found', $result->reason);
    }

    public function test_contracts_view_permission_is_required(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$contract] = $this->openContractWithSchedule($workspace, $owner, 150);

        $agent = $this->attachAgentWithPermissions($workspace, ['finance.view', 'invoices.view']);
        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'ما وضع عقد '.$contract->contract_number.'؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'contracts.details');
        $this->assertSame('permission_denied', $fact['reason'] ?? null);
    }

    public function test_tool_registry_includes_phase3_tools(): void
    {
        $registry = app(FinanceAssistantToolRegistry::class);
        $names = array_map(fn ($tool) => $tool->name(), $registry->all());

        foreach ([
            'contracts.lookup',
            'contracts.details',
            'contracts.balance',
            'contracts.invoices',
            'contracts.unbilled',
            'contracts.schedules',
            'billing_schedules.upcoming',
        ] as $expected) {
            $this->assertContains($expected, $names, "Missing tool: {$expected}");
        }
    }

    /**
     * @return array{0: Contract, 1: FinanceBillingSchedule}
     */
    private function openContractWithSchedule(
        Workspace $workspace,
        User $owner,
        float $value = 230,
        int $occurrences = 2,
        ?Customer $customer = null,
    ): array {
        $customer ??= $this->customer($workspace, 'Contract Customer '.uniqid());

        $contract = $this->runInWorkspace($workspace, function () use ($workspace, $owner, $customer, $value): Contract {
            $draft = app(ContractService::class)->create($workspace, [
                'title' => 'Assistant Contract',
                'customer_id' => $customer->id,
                'currency' => 'SAR',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'items' => [[
                    'title' => 'Service',
                    'quantity' => 1,
                    'unit_price' => $value,
                    'tax_rate' => 0,
                ]],
            ], (int) $owner->id);

            return app(ContractService::class)->activate($draft, (int) $owner->id);
        });

        $schedule = $this->runInWorkspace($workspace, function () use ($contract, $owner, $occurrences) {
            return app(BillingScheduleService::class)->createFromContract($contract, [
                'title' => 'Installment',
                'frequency' => 'installment',
                'total_occurrences' => $occurrences,
                'start_date' => now()->toDateString(),
                'status' => FinanceBillingSchedule::STATUS_ACTIVE,
            ], (int) $owner->id);
        });

        return [$contract, $schedule];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function executeTool(Workspace $workspace, User $user, string $tool, array $arguments): FinanceAssistantToolResult
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(FinanceAssistantToolExecutor::class)->execute($workspace, $user, [
            'name' => $tool,
            'arguments' => $arguments,
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Phase3 Co'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['finance', 'products', 'orders', 'customers', 'payments'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'ai'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'ai', 'enabled' => true, 'source' => 'manual']
        );

        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->orderByDesc('price')->first();
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

        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return [$user, $workspace->fresh()];
    }

    private function customer(Workspace $workspace, string $name): Customer
    {
        app(WorkspaceContext::class)->set($workspace);

        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid('', true).'@example.com',
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function runInWorkspace(Workspace $workspace, callable $callback)
    {
        app(WorkspaceContext::class)->set($workspace);

        return $callback();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function attachAgentWithPermissions(Workspace $workspace, array $permissions): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $agent = User::factory()->create();
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $role = Role::findOrCreate('phase3_'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);
        $agent->assignRole($role);

        return $agent;
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
