<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Models\Finance\FinancePayrollAdjustment;
use App\Models\Finance\FinanceSalaryAdvance;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceFlutterPeopleObligationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_owner_can_manage_company_employee_salary_and_advance_through_finance_api(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $create = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/employees', [
                'full_name' => 'موظف الشركة أ',
                'job_title' => 'محاسب',
                'basic_salary' => 1500,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.full_name', 'موظف الشركة أ');

        $employeeId = (int) $create->json('data.id');
        $this->assertNotSame(0, $employeeId);
        $this->assertDatabaseHas('finance_employees', [
            'id' => $employeeId,
            'workspace_id' => $workspace->id,
            'full_name' => 'موظف الشركة أ',
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/employees/'.$employeeId.'/payroll-records', [
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end' => now()->endOfMonth()->toDateString(),
                'basic_salary' => 1500,
                'allowances_total' => 0,
                'deductions_total' => 0,
                'payment_status' => 'partial',
            ])
            ->assertOk()
            ->assertJsonPath('data.net_amount', '1500.00')
            ->assertJsonPath('data.remaining', '1500.00')
            ->assertJsonPath('data.payment_status', 'partial');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/salary-advances', [
                'finance_employee_id' => $employeeId,
                'amount' => 800,
                'issued_at' => now()->toDateString(),
                'type' => 'salary_advance',
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '800.00')
            ->assertJsonPath('data.remaining_amount', '800.00')
            ->assertJsonPath('data.status', 'open');

        $advanceId = (int) FinanceSalaryAdvance::withoutGlobalScopes()->value('id');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/salary-advances/'.$advanceId.'/repay', [
                'payment_date' => now()->toDateString(),
                'amount' => 300,
                'method' => 'cash',
            ])
            ->assertOk()
            ->assertJsonPath('data.remaining_amount', '500.00')
            ->assertJsonPath('data.settled_amount', '300.00');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/payroll-adjustments', [
                'type' => 'bonus',
                'finance_employee_id' => $employeeId,
                'title' => 'مكافأة أداء',
                'amount' => 200,
                'effective_date' => now()->toDateString(),
                'status' => 'approved',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'bonus')
            ->assertJsonPath('data.amount', '200.00');

        $adjustment = FinancePayrollAdjustment::withoutGlobalScopes()->firstOrFail();
        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/payroll-adjustments/'.$adjustment->id.'/post')
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $show = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/employees/'.$employeeId)
            ->assertOk();

        $this->assertSame('1500.00', $show->json('data.financial_summary.remaining'));
        $this->assertSame('500.00', $show->json('data.financial_summary.advance_remaining'));
        $this->assertSame('200.00', $show->json('data.financial_summary.bonuses_total'));
        $this->assertNotEmpty($show->json('data.payroll_records'));
        $this->assertNotEmpty($show->json('data.advances'));

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/payroll')
            ->assertOk()
            ->assertJsonPath('data.cards.company_employees', 1);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.cards.company_employees', 1)
            ->assertJsonPath('data.cards.open_advances_total', '500.00');

        $me = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/auth/me')
            ->assertOk();
        $this->assertTrue((bool) ($me->json('data.permissions')['payroll.view'] ?? false));
        $this->assertTrue((bool) ($me->json('data.permissions')['finance.salary_advances.manage'] ?? false));
    }

    public function test_agent_cannot_access_company_people_finance_api(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'employee_code' => 'FEMP-00001',
            'full_name' => 'موظف محجوب',
            'basic_salary' => 1000,
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $agent = User::factory()->create();
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($agent);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->getJson('/api/finance/v1/employees')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_wrong_workspace_cannot_read_company_employee(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('Workspace A');
        $employee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'employee_code' => 'FEMP-00999',
            'full_name' => 'موظف أ',
            'basic_salary' => 1200,
            'status' => 'active',
            'created_by' => $ownerA->id,
        ]);

        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Workspace B');
        Sanctum::actingAs($ownerB);

        $this->withHeaders($this->workspaceHeader($workspaceB))
            ->getJson('/api/finance/v1/employees/'.$employee->id)
            ->assertStatus(404);
    }

    public function test_payroll_record_gross_and_net_come_from_server(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $employee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'employee_code' => 'FEMP-00077',
            'full_name' => 'موظف صافي',
            'basic_salary' => 2000,
            'status' => 'active',
            'created_by' => $owner->id,
        ]);
        Sanctum::actingAs($owner);

        $this->withHeaders($this->workspaceHeader($workspace))
            ->postJson('/api/finance/v1/employees/'.$employee->id.'/payroll-records', [
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'basic_salary' => 2000,
                'allowances_total' => 300,
                'deductions_total' => 150,
                'payment_status' => 'pending',
            ])
            ->assertOk()
            ->assertJsonPath('data.gross_amount', '2300.00')
            ->assertJsonPath('data.net_amount', '2150.00');

        $record = FinanceEmployeePayrollRecord::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('2300.00', (string) $record->gross_amount);
        $this->assertSame('2150.00', (string) $record->net_amount);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Finance People Workspace'): array
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
