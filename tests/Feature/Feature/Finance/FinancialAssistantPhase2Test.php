<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\Assistant\FinanceAssistantToolExecutor;
use App\Services\Finance\Assistant\FinanceAssistantToolRegistry;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\Assistant\FinancialAssistantMetricRegistry;
use App\Services\Finance\FinanceBootstrapService;
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

class FinancialAssistantPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance_assistant.planner' => 'heuristic']);
        RateLimiter::clear('mobile-ai');
        $this->seed(FoundationSeeder::class);
    }

    public function test_invoices_lookup_finds_invoice_by_number(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Lookup Buyer');
        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id, '2500.00');

        $result = $this->executeTool($workspace, $owner, 'invoices.lookup', [
            'invoice_number' => $invoice->invoice_number,
        ]);

        $this->assertTrue($result->available);
        $this->assertSame('found', $result->data['status'] ?? null);
        $this->assertSame($invoice->invoice_number, $result->data['invoice']['invoice_number'] ?? null);
    }

    public function test_invoices_details_includes_payment_fields(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Detail Buyer');
        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id, '1800.00');

        $result = $this->executeTool($workspace, $owner, 'invoices.details', [
            'invoice_number' => $invoice->invoice_number,
        ]);

        $this->assertTrue($result->available);
        $this->assertSame('1800.00', $result->data['invoice']['total'] ?? null);
        $this->assertArrayHasKey('payments', $result->data['invoice']);
    }

    public function test_invoices_overdue_lists_overdue_invoices(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Overdue Buyer');
        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id, '900.00', [
            'due_date' => now()->subDays(3)->toDateString(),
            'issue_date' => now()->subDays(10)->toDateString(),
        ]);
        app(InvoiceService::class)->refreshIssuedPaymentStatuses((int) $workspace->id);

        $result = $this->executeTool($workspace, $owner, 'invoices.overdue', ['limit' => 5]);

        $this->assertTrue($result->available);
        $this->assertGreaterThanOrEqual(1, (int) ($result->data['count'] ?? 0));
        $this->assertContains($invoice->invoice_number, collect($result->data['invoices'] ?? [])->pluck('invoice_number')->all());
    }

    public function test_customers_balance_returns_outstanding(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Mohammed AR');
        $this->issueSales($workspace, $customer, (int) $owner->id, '1200.00');

        $result = $this->executeTool($workspace, $owner, 'customers.balance', [
            'customer_name' => 'Mohammed',
        ]);

        $this->assertTrue($result->available);
        $this->assertSame('1200.00', $result->data['value'] ?? null);
    }

    public function test_invoices_by_customer_lists_customer_invoices(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Sara Customer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '500.00');
        $this->issueSales($workspace, $customer, (int) $owner->id, '700.00');

        $result = $this->executeTool($workspace, $owner, 'invoices.by_customer', [
            'customer_name' => 'Sara',
        ]);

        $this->assertTrue($result->available);
        $this->assertSame(2, (int) ($result->data['count'] ?? 0));
    }

    public function test_invoices_summary_uses_invoice_sales_total_metric(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Summary Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '3000.00');
        Sanctum::actingAs($owner);

        $result = $this->executeTool($workspace, $owner, 'invoices.summary', ['preset' => 'month']);

        $this->assertTrue($result->available);
        $this->assertSame(FinancialAssistantMetricRegistry::INVOICE_SALES_TOTAL, $result->data['source'] ?? null);
        $this->assertSame('3000.00', $result->data['total'] ?? null);
    }

    public function test_invoice_lookup_is_scoped_to_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('Workspace A');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Workspace B');
        $customer = $this->customer($workspaceA, 'Tenant A Buyer');
        $invoice = $this->issueSales($workspaceA, $customer, (int) $ownerA->id, '1000.00');

        $result = $this->executeTool($workspaceB, $ownerB, 'invoices.lookup', [
            'invoice_number' => $invoice->invoice_number,
        ]);

        $this->assertFalse($result->available);
        $this->assertSame('not_found', $result->reason);
    }

    public function test_invoices_view_permission_is_required(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'RBAC Buyer');
        $invoice = $this->issueSales($workspace, $customer, (int) $owner->id, '400.00');

        $agent = $this->attachAgentWithPermissions($workspace, ['finance.view']);
        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'اعرض '.$invoice->invoice_number,
        ], $this->workspaceHeader($workspace))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'invoices.details');
        $this->assertSame('permission_denied', $fact['reason'] ?? null);
    }

    public function test_tool_registry_includes_phase2_tools(): void
    {
        $registry = app(FinanceAssistantToolRegistry::class);
        $names = array_map(fn ($tool) => $tool->name(), $registry->all());

        foreach ([
            'invoices.lookup',
            'invoices.details',
            'invoices.overdue',
            'invoices.by_customer',
            'invoices.summary',
            'invoices.count',
            'customers.lookup',
            'customers.balance',
            'customers.invoices',
            'customers.overdue',
            'customers.statement',
        ] as $expected) {
            $this->assertContains($expected, $names, "Missing tool: {$expected}");
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issueSales(
        Workspace $workspace,
        Customer $customer,
        int $actorId,
        string $amount,
        array $overrides = [],
    ): FinanceInvoice {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, array_merge([
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'Assistant line',
                'quantity' => 1,
                'unit_price' => $amount,
                'discount' => 0,
                'tax_rate' => 0,
                'tax_type' => TaxProfileType::ZeroRated->value,
            ]],
        ], $overrides), $actorId);
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
    private function createWorkspaceOwner(string $name = 'Phase2 Co'): array
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
        app(WorkspaceContext::class)->set($workspace);

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

        $role = Role::findOrCreate('phase2_'.md5(implode(',', $permissions)), 'web');
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
