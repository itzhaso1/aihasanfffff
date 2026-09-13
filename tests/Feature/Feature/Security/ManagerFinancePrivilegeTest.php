<?php

namespace Tests\Feature\Feature\Security;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceFiscalYear;
use App\Models\Finance\FinanceInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Authorization\FoundationRolePermissions;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ManagerFinancePrivilegeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $membershipOnlyManager;

    private User $accountant;

    private User $cashier;

    private User $staff;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    private Customer $customer;

    private FinanceInvoice $draftInvoice;

    private FinanceInvoice $issuedInvoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);

        [$this->owner, $this->workspace] = $this->createWorkspaceOwner('P05 Workspace A');
        [, $this->otherWorkspace] = $this->createWorkspaceOwner('P05 Workspace B');
        app(WorkspaceContext::class)->set($this->workspace);

        $this->manager = $this->attachMember($this->workspace, 'manager', 'manager');
        $this->membershipOnlyManager = $this->attachMember($this->workspace, 'manager');
        $this->accountant = $this->attachMember($this->workspace, 'member', 'accountant');
        $this->cashier = $this->attachMember($this->workspace, 'agent', 'agent');
        $this->staff = $this->attachMember($this->workspace, 'member');

        $this->customer = $this->makeBuyer($this->workspace, 'P05 Buyer');
        $this->draftInvoice = $this->createInvoice($this->workspace, $this->customer, (int) $this->owner->id, 'draft');
        $this->issuedInvoice = $this->createInvoice($this->workspace, $this->customer, (int) $this->owner->id, 'issued');
        app(WorkspaceContext::class)->clear();
    }

    public function test_foundation_manager_template_excludes_accounting_admin_keys(): void
    {
        $role = Role::findByName('manager', 'web');
        $names = $role->permissions->pluck('name')->all();

        foreach (FoundationRolePermissions::dangerousFinancePermissions() as $permission) {
            $this->assertNotContains($permission, $names, $permission.' must not be on manager');
        }

        foreach (['finance.view', 'invoices.view', 'invoices.create', 'invoices.edit', 'reports.view', 'customers.manage'] as $permission) {
            $this->assertContains($permission, $names);
        }

        $accountant = Role::findByName('accountant', 'web')->permissions->pluck('name')->all();
        foreach (['accounting.manage', 'finance.settings', 'finance.fiscal_years.manage', 'payroll.manage'] as $permission) {
            $this->assertContains($permission, $accountant);
        }
    }

    public function test_m1_manager_can_view_finance_summary(): void
    {
        $this->financeAs($this->manager)->getJson('/api/finance/v1/dashboard')->assertOk();
        $this->financeAs($this->membershipOnlyManager)->getJson('/api/finance/v1/dashboard')->assertOk();
        $this->financeAs($this->owner)->getJson('/api/finance/v1/dashboard')->assertOk();
        $this->financeAs($this->accountant)->getJson('/api/finance/v1/dashboard')->assertOk();
        $this->financeAs($this->staff)->getJson('/api/finance/v1/dashboard')->assertStatus(403);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->workspaceHeader($this->workspace))
            ->getJson('/api/finance/v1/dashboard')
            ->assertStatus(401);

        $this->webAs($this->manager)
            ->get(route('workspace.finance.dashboard'))
            ->assertOk();
    }

    public function test_m2_manager_can_view_invoice(): void
    {
        $uri = '/api/finance/v1/sales-invoices/'.$this->issuedInvoice->id;
        $this->financeAs($this->manager)->getJson($uri)->assertOk()->assertJsonPath('data.id', $this->issuedInvoice->id);
        $this->financeAs($this->owner)->getJson($uri)->assertOk();
        $this->financeAs($this->accountant)->getJson($uri)->assertOk();
        $this->financeAs($this->staff)->getJson($uri)->assertStatus(403);

        $this->webAs($this->manager)
            ->get(route('workspace.finance.invoices.show', $this->issuedInvoice))
            ->assertOk();
    }

    public function test_m3_manager_can_create_operational_invoice(): void
    {
        $payload = $this->invoicePayload($this->customer->id);
        $this->financeAs($this->manager)
            ->postJson('/api/finance/v1/sales-invoices', $payload)
            ->assertCreated();
        $this->financeAs($this->owner)
            ->postJson('/api/finance/v1/sales-invoices', $payload)
            ->assertCreated();
        $this->financeAs($this->accountant)
            ->postJson('/api/finance/v1/sales-invoices', $payload)
            ->assertCreated();
        $this->financeAs($this->cashier)
            ->postJson('/api/finance/v1/sales-invoices', $payload)
            ->assertStatus(403);
        $this->financeAs($this->staff)
            ->postJson('/api/finance/v1/sales-invoices', $payload)
            ->assertStatus(403);
    }

    public function test_m4_manager_can_edit_draft_invoice(): void
    {
        $payload = $this->invoicePayload($this->customer->id);
        $payload['notes'] = 'manager draft edit';
        $uri = '/api/finance/v1/sales-invoices/'.$this->draftInvoice->id;

        $this->financeAs($this->manager)->putJson($uri, $payload)->assertOk();
        $this->financeAs($this->owner)->putJson($uri, $payload)->assertOk();
        $this->financeAs($this->cashier)->putJson($uri, $payload)->assertStatus(403);
    }

    public function test_m5_m6_manager_cannot_view_or_post_journals(): void
    {
        $this->financeAs($this->manager)->getJson('/api/finance/v1/accounting')->assertStatus(403);
        $this->financeAs($this->membershipOnlyManager)->getJson('/api/finance/v1/accounting')->assertStatus(403);
        $this->financeAs($this->cashier)->getJson('/api/finance/v1/accounting')->assertStatus(403);
        $this->financeAs($this->staff)->getJson('/api/finance/v1/accounting')->assertStatus(403);
        $this->financeAs($this->owner)->getJson('/api/finance/v1/accounting')->assertOk();
        $this->financeAs($this->accountant)->getJson('/api/finance/v1/accounting')->assertOk();

        $this->webAs($this->manager)
            ->get(route('workspace.finance.accounting.dashboard'))
            ->assertForbidden();
        $this->webAs($this->owner)
            ->get(route('workspace.finance.accounting.dashboard'))
            ->assertOk();
    }

    public function test_m7_manager_cannot_alter_chart_or_settings(): void
    {
        $this->financeAs($this->manager)->getJson('/api/finance/v1/settings')->assertStatus(403);
        $this->financeAs($this->manager)
            ->putJson('/api/finance/v1/settings', ['company_name' => 'Hacked'])
            ->assertStatus(403);
        $this->financeAs($this->cashier)
            ->putJson('/api/finance/v1/settings', ['company_name' => 'Hacked'])
            ->assertStatus(403);

        $this->financeAs($this->owner)
            ->putJson('/api/finance/v1/settings', ['company_name' => 'Owner Co'])
            ->assertOk();
        $this->financeAs($this->accountant)
            ->putJson('/api/finance/v1/settings', ['company_name' => 'Books Co'])
            ->assertOk();
    }

    public function test_m8_manager_cannot_close_or_reopen_fiscal_year(): void
    {
        $year = FinanceFiscalYear::withoutGlobalScopes()
            ->where('workspace_id', $this->workspace->id)
            ->firstOrFail();
        $close = '/api/finance/v1/fiscal-years/'.$year->id.'/close';
        $open = '/api/finance/v1/fiscal-years/'.$year->id.'/open';

        $this->financeAs($this->manager)->postJson($close)->assertStatus(403);
        $this->financeAs($this->manager)->postJson($open)->assertStatus(403);
        $this->financeAs($this->cashier)->postJson($close)->assertStatus(403);
        $this->financeAs($this->staff)->postJson($close)->assertStatus(403);

        $accountantClose = $this->financeAs($this->accountant)->postJson($close);
        $this->assertNotSame(403, $accountantClose->status());
        $ownerOpen = $this->financeAs($this->owner)->postJson($open);
        $this->assertNotSame(403, $ownerOpen->status());

        $this->webAs($this->manager)
            ->post(route('workspace.finance.fiscal-years.close', $year))
            ->assertForbidden();
    }

    public function test_m9_manager_cannot_create_treasury_transfer(): void
    {
        $payload = [
            'from_treasury_account_id' => 1,
            'to_treasury_account_id' => 2,
            'amount' => 10,
            'transfer_date' => now()->toDateString(),
        ];

        $this->financeAs($this->manager)->postJson('/api/finance/v1/treasury/transfers', $payload)->assertStatus(403);
        $this->financeAs($this->membershipOnlyManager)->postJson('/api/finance/v1/treasury/transfers', $payload)->assertStatus(403);
        $this->financeAs($this->cashier)->postJson('/api/finance/v1/treasury/transfers', $payload)->assertStatus(403);
        $this->financeAs($this->staff)->postJson('/api/finance/v1/treasury/transfers', $payload)->assertStatus(403);

        $owner = $this->financeAs($this->owner)->postJson('/api/finance/v1/treasury/transfers', $payload);
        $this->assertNotSame(403, $owner->status());
        $accountant = $this->financeAs($this->accountant)->postJson('/api/finance/v1/treasury/transfers', $payload);
        $this->assertNotSame(403, $accountant->status());

        $this->webAs($this->manager)
            ->post(route('workspace.finance.treasury.transfers.store'), $payload)
            ->assertForbidden();
    }

    public function test_m10_manager_cannot_run_bank_reconciliation(): void
    {
        $payload = [
            'treasury_account_id' => 1,
            'statement_date' => now()->toDateString(),
            'opening_balance' => 0,
            'closing_balance' => 0,
        ];

        $this->financeAs($this->manager)->postJson('/api/finance/v1/treasury/statements', $payload)->assertStatus(403);
        $this->financeAs($this->cashier)->postJson('/api/finance/v1/treasury/statements', $payload)->assertStatus(403);
        $owner = $this->financeAs($this->owner)->postJson('/api/finance/v1/treasury/statements', $payload);
        $this->assertNotSame(403, $owner->status());
    }

    public function test_m11_manager_cannot_mutate_payroll(): void
    {
        $employee = [
            'full_name' => 'Payroll Target',
            'basic_salary' => 1000,
            'status' => 'active',
        ];
        $adjustment = [
            'type' => 'bonus',
            'finance_employee_id' => 1,
            'title' => 'Bonus',
            'amount' => 50,
            'effective_date' => now()->toDateString(),
        ];

        $this->financeAs($this->manager)->postJson('/api/finance/v1/employees', $employee)->assertStatus(403);
        $this->financeAs($this->manager)->getJson('/api/finance/v1/employees')->assertStatus(403);
        $this->financeAs($this->manager)->postJson('/api/finance/v1/payroll-adjustments', $adjustment)->assertStatus(403);
        $this->financeAs($this->cashier)->postJson('/api/finance/v1/employees', $employee)->assertStatus(403);

        $this->financeAs($this->owner)->postJson('/api/finance/v1/employees', $employee)->assertCreated();
        $this->financeAs($this->accountant)
            ->postJson('/api/finance/v1/employees', ['full_name' => 'Books Employee', 'basic_salary' => 900, 'status' => 'active'])
            ->assertCreated();

        $this->webAs($this->manager)
            ->get(route('workspace.finance.employees.index'))
            ->assertForbidden();
    }

    public function test_m12_manager_cannot_change_tax_or_accounting_settings(): void
    {
        $tax = [
            'name' => 'Hack VAT',
            'code' => 'HACK15',
            'type' => 'standard',
            'rate' => 15,
        ];

        $this->financeAs($this->manager)->postJson('/api/finance/v1/settings/tax-rates', $tax)->assertStatus(403);
        $this->financeAs($this->cashier)->postJson('/api/finance/v1/settings/tax-rates', $tax)->assertStatus(403);
        $this->financeAs($this->owner)->postJson('/api/finance/v1/settings/tax-rates', $tax)->assertOk();
        $this->financeAs($this->accountant)
            ->postJson('/api/finance/v1/settings/tax-rates', [...$tax, 'code' => 'BOOK15'])
            ->assertOk();

        $this->webAs($this->manager)
            ->put(route('workspace.finance.settings.company.update'), ['company_name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_m13_manager_cannot_manage_financial_permissions(): void
    {
        $this->financeAs($this->manager)
            ->postJson('/api/roles-permissions/sync-permissions', [
                'user_id' => $this->staff->id,
                'permissions' => ['accounting.manage'],
            ])
            ->assertForbidden();

        $this->financeAs($this->cashier)
            ->postJson('/api/roles-permissions/sync-permissions', [
                'user_id' => $this->staff->id,
                'permissions' => ['accounting.manage'],
            ])
            ->assertForbidden();

        $this->financeAs($this->owner)
            ->postJson('/api/roles-permissions/sync-permissions', [
                'user_id' => $this->staff->id,
                'permissions' => ['invoices.view'],
            ])
            ->assertOk();
    }

    public function test_m14_manager_can_export_operational_invoices_not_accounting(): void
    {
        $this->financeAs($this->manager)
            ->get('/api/finance/v1/exports/invoices')
            ->assertOk();
        $this->financeAs($this->owner)
            ->get('/api/finance/v1/exports/invoices')
            ->assertOk();
        $this->financeAs($this->staff)
            ->get('/api/finance/v1/exports/invoices')
            ->assertStatus(403);

        $this->webAs($this->manager)
            ->get(route('workspace.finance.exports.download', 'invoices'))
            ->assertOk();
    }

    public function test_m15_manager_cannot_create_credit_note(): void
    {
        $payload = [
            'invoice_id' => $this->issuedInvoice->id,
            'type' => 'credit',
            'reason' => 'Privilege check',
            'issue_date' => now()->toDateString(),
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ];

        $this->financeAs($this->manager)->postJson('/api/finance/v1/credit-notes', $payload)->assertStatus(403);
        $this->financeAs($this->cashier)->postJson('/api/finance/v1/credit-notes', $payload)->assertStatus(403);
        $this->financeAs($this->staff)->postJson('/api/finance/v1/credit-notes', $payload)->assertStatus(403);
        $this->financeAs($this->owner)->postJson('/api/finance/v1/credit-notes', $payload)->assertCreated();
        $this->financeAs($this->accountant)->postJson('/api/finance/v1/credit-notes', $payload)->assertCreated();
    }

    public function test_manager_permission_map_is_operational_not_admin(): void
    {
        $this->owner->forceFill(['password' => 'password'])->save();
        $this->manager->forceFill(['password' => 'password'])->save();

        $managerLogin = $this->postJson('/api/finance/v1/auth/login', [
            'email' => $this->manager->email,
            'password' => 'password',
            'device_type' => 'finance',
        ])->assertOk();

        $permissions = $managerLogin->json('data.permissions');
        $this->assertTrue((bool) ($permissions['finance.view'] ?? false));
        $this->assertTrue((bool) ($permissions['invoices.view'] ?? false));
        $this->assertTrue((bool) ($permissions['invoices.create'] ?? false));
        $this->assertFalse((bool) ($permissions['finance.settings'] ?? true));
        $this->assertFalse((bool) ($permissions['accounting.manage'] ?? true));
        $this->assertFalse((bool) ($permissions['finance.fiscal_years.manage'] ?? true));
        $this->assertFalse((bool) ($permissions['payroll.manage'] ?? true));
        $this->assertFalse((bool) ($permissions['invoices.credit'] ?? true));
        $this->assertFalse((bool) ($permissions['notes.create'] ?? true));

        $ownerLogin = $this->postJson('/api/finance/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
            'device_type' => 'finance',
        ])->assertOk();
        $this->assertTrue((bool) ($ownerLogin->json('data.permissions')['accounting.manage'] ?? false));
    }

    public function test_manager_cannot_act_on_another_workspace(): void
    {
        $this->financeAs($this->manager, $this->otherWorkspace)
            ->getJson('/api/finance/v1/dashboard')
            ->assertStatus(403);

        $this->financeAs($this->manager, $this->otherWorkspace)
            ->postJson('/api/finance/v1/treasury/transfers', [
                'from_treasury_account_id' => 1,
                'to_treasury_account_id' => 2,
                'amount' => 10,
                'transfer_date' => now()->toDateString(),
            ])
            ->assertStatus(403);

        $this->financeAs($this->manager)
            ->getJson('/api/finance/v1/sales-invoices/'.$this->issuedInvoice->id)
            ->assertOk();
    }

    public function test_agent_template_did_not_gain_finance_admin_keys(): void
    {
        $agent = Role::findByName('agent', 'web')->permissions->pluck('name')->all();
        foreach (['accounting.manage', 'finance.settings', 'finance.fiscal_years.manage', 'payroll.manage', 'workspace.manage'] as $permission) {
            $this->assertNotContains($permission, $agent);
        }
    }

    public function test_sync_foundation_roles_command_is_repeatable(): void
    {
        $this->artisan('permissions:sync-foundation-roles')->assertSuccessful();
        $this->artisan('permissions:sync-foundation-roles')->assertSuccessful();

        $manager = Role::findByName('manager', 'web')->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame(
            collect(FoundationRolePermissions::manager())->sort()->values()->all(),
            $manager
        );
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name): array
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

        foreach (['finance', 'pos', 'products', 'orders', 'customers', 'payments', 'payment_gateway'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

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

        return [$user, $workspace->fresh()];
    }

    private function attachMember(Workspace $workspace, string $membershipRole, ?string $spatieRole = null): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => $membershipRole,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        if ($spatieRole !== null) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($workspace->id);
            $user->assignRole($spatieRole);
            $registrar->setPermissionsTeamId(null);
        }

        return $user;
    }

    private function makeBuyer(Workspace $workspace, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@example.com',
            'vat_number' => '300111111111113',
        ]);
    }

    private function createInvoice(Workspace $workspace, Customer $customer, int $actorId, string $status): FinanceInvoice
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, array_merge($this->invoicePayload($customer->id), [
            'invoice_status' => $status,
        ]), $actorId);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(int $customerId): array
    {
        return [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'draft',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ];
    }

    private function financeAs(User $user, ?Workspace $workspace = null)
    {
        $workspace ??= $this->workspace;
        app(WorkspaceContext::class)->clear();
        Sanctum::actingAs($user);

        return $this->withHeaders($this->workspaceHeader($workspace));
    }

    private function webAs(User $user, ?Workspace $workspace = null)
    {
        $workspace ??= $this->workspace;

        return $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id]);
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
