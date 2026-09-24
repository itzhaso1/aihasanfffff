<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Authorization\FinanceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceApiFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_finance_feature_enabled_allows_representative_finance_endpoints(): void
    {
        [$owner, $workspace] = $this->createFinanceWorkspace(financeEnabled: true);
        Sanctum::actingAs($owner);

        $headers = $this->workspaceHeader($workspace);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.workspace.finance_enabled', true);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/sales-invoices')
            ->assertOk()
            ->assertJsonPath('success', true);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Finance Gate Buyer',
            'phone' => '0500000001',
            'email' => 'finance-gate-buyer@example.com',
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices', [
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'draft',
                'items' => [[
                    'product_name' => 'خدمة',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_finance_feature_disabled_blocks_representative_finance_endpoints_despite_permissions(): void
    {
        [$owner, $workspace] = $this->createFinanceWorkspace(financeEnabled: false);
        Sanctum::actingAs($owner);

        $headers = $this->workspaceHeader($workspace);

        foreach ([
            ['GET', '/api/finance/v1/bootstrap'],
            ['GET', '/api/finance/v1/sales-invoices'],
            ['GET', '/api/finance/v1/dashboard'],
        ] as [$method, $uri]) {
            $this->withHeaders($headers)
                ->json($method, $uri)
                ->assertStatus(402)
                ->assertJsonPath('error', 'feature_not_available')
                ->assertJsonPath('feature', 'finance');
        }

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Blocked Buyer',
            'phone' => '0500000002',
            'email' => 'blocked-buyer@example.com',
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices', [
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'draft',
                'items' => [[
                    'product_name' => 'خدمة',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]],
            ])
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'finance');
    }

    public function test_owner_with_all_finance_permissions_is_still_blocked_without_finance_feature(): void
    {
        [$owner, $workspace] = $this->createFinanceWorkspace(financeEnabled: false);
        Sanctum::actingAs($owner);

        $financeAccess = app(FinanceAccess::class);
        $this->assertTrue($financeAccess->isFinanceAdministrator($owner, $workspace));
        $this->assertTrue($financeAccess->can($owner, $workspace, 'invoices.create'));
        $this->assertTrue($financeAccess->can($owner, $workspace, 'finance.settings'));

        $this->withHeaders($this->workspaceHeader($workspace))
            ->putJson('/api/finance/v1/settings', [
                'company_name' => 'Should Not Save',
            ])
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'finance');
    }

    public function test_non_member_is_rejected_by_existing_workspace_membership_guard(): void
    {
        [, $workspace] = $this->createFinanceWorkspace(financeEnabled: true);
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/bootstrap')
            ->assertForbidden();
    }

    public function test_finance_enabled_metadata_on_login_does_not_replace_feature_gate_enforcement(): void
    {
        [$owner, $workspace] = $this->createFinanceWorkspace(financeEnabled: false);
        $owner->forceFill(['password' => 'password'])->save();

        $login = $this->postJson('/api/finance/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
            'device_type' => 'finance',
        ])->assertOk()
            ->assertJsonPath('data.finance_enabled', false)
            ->assertJsonPath('data.workspace.finance_enabled', false);

        $token = (string) $login->json('data.token');
        $this->assertNotEmpty($token);

        $this->withHeaders($this->workspaceHeader($workspace) + [
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/finance/v1/bootstrap')
            ->assertStatus(402)
            ->assertJsonPath('error', 'feature_not_available')
            ->assertJsonPath('feature', 'finance');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createFinanceWorkspace(bool $financeEnabled): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Finance Gate Co',
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
                [
                    'workspace_id' => $workspace->id,
                    'feature_key' => $feature,
                    'enabled' => $feature === 'finance' ? $financeEnabled : true,
                    'source' => 'manual',
                ]
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

        if ($financeEnabled) {
            app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return [$user, $workspace->fresh()];
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
