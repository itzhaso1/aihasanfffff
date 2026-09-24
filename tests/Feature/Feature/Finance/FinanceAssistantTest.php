<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\Assistant\FinanceAssistantOrchestrator;
use App\Services\Finance\ExpenseService;
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

class FinanceAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance_assistant.planner' => 'heuristic']);
        RateLimiter::clear('mobile-ai');
        $this->seed(FoundationSeeder::class);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        [, $workspace] = $this->createWorkspaceOwner();

        $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], ['X-Workspace-Id' => (string) $workspace->id])
            ->assertUnauthorized();
    }

    public function test_non_member_cannot_access_assistant(): void
    {
        [, $workspaceA] = $this->createWorkspaceOwner('Finance A');
        [, $workspaceB] = $this->createWorkspaceOwner('Finance B');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], $this->workspaceHeader($workspaceA))
            ->assertForbidden();
    }

    public function test_assistant_requires_ai_feature(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner(enableAi: false);
        Sanctum::actingAs($owner);

        $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], $this->workspaceHeader($workspace))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_sales_today_returns_structured_facts_from_ledger(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Buyer Today');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['answer', 'facts', 'tools_used', 'period'],
            ]);

        $this->assertContains('sales.today', $response->json('data.tools_used'));
        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'sales.today');
        $this->assertTrue((bool) ($fact['available'] ?? false));
        $this->assertSame('5000.00', $fact['data']['value'] ?? null);
        $this->assertNotEmpty($response->json('data.answer'));
    }

    public function test_net_profit_and_expenses_compound_question(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Compound');
        $customer = $this->customer($workspace, 'Compound Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $this->postExpense($workspace, (int) $owner->id, '2500.00');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا هذا الشهر ومصاريفنا وصافي ربحنا؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $tools = $response->json('data.tools_used') ?? [];
        $this->assertContains('sales.period', $tools);
        $this->assertContains('expenses.period', $tools);
        $this->assertContains('profit.net', $tools);

        $netFact = collect($response->json('data.facts'))->firstWhere('tool', 'profit.net');
        $this->assertSame('7500.00', $netFact['data']['value'] ?? null);
    }

    public function test_payroll_period_tool_uses_paid_records(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Payroll');
        $employee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'full_name' => 'Finance Staff',
            'employee_code' => 'FEMP-ASSIST-1',
            'status' => 'active',
            'basic_salary' => 1500,
            'created_by' => $owner->id,
        ]);
        FinanceEmployeePayrollRecord::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'finance_employee_id' => $employee->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'basic_salary' => 1500,
            'allowances_total' => 0,
            'deductions_total' => 0,
            'gross_amount' => 1500,
            'net_amount' => 1500,
            'payment_status' => 'paid',
            'paid_at' => now(),
            'created_by' => $owner->id,
        ]);
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم دفعنا رواتب؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertContains('payroll.period', $response->json('data.tools_used'));
        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'payroll.period');
        $this->assertSame('1500.00', $fact['data']['value'] ?? null);
    }

    public function test_employee_count_question_uses_payroll_employees_count_tool(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Employees Count');
        FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'full_name' => 'Active One',
            'employee_code' => 'FEMP-CNT-1',
            'status' => 'active',
            'basic_salary' => 1200,
            'created_by' => $owner->id,
        ]);
        FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'full_name' => 'Active Two',
            'employee_code' => 'FEMP-CNT-2',
            'status' => 'active',
            'basic_salary' => 1300,
            'created_by' => $owner->id,
        ]);
        FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'full_name' => 'Inactive',
            'employee_code' => 'FEMP-CNT-3',
            'status' => 'inactive',
            'basic_salary' => 900,
            'created_by' => $owner->id,
        ]);
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم موظف عنا',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertContains('payroll.employees_count', $response->json('data.tools_used'));
        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'payroll.employees_count');
        $this->assertSame(2, $fact['data']['count'] ?? null);
        $this->assertStringContainsString('2', (string) $response->json('data.answer'));
    }

    public function test_suppliers_payable_and_inventory_value_tools(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Payables');
        Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Tracked SKU',
            'slug' => 'tracked-sku-assist',
            'sku' => 'SKU-ASSIST',
            'price' => 100,
            'cost_price' => 20,
            'stock' => 5,
            'inventory_tracking' => true,
            'is_active' => true,
        ]);
        Sanctum::actingAs($owner);

        $inventory = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم قيمة المخزون؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $inventoryFact = collect($inventory->json('data.facts'))->firstWhere('tool', 'inventory.value');
        $this->assertSame('100.00', $inventoryFact['data']['value'] ?? null);

        $payables = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم علينا للموردين؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $this->assertContains('suppliers.payable', $payables->json('data.tools_used'));
    }

    public function test_compare_periods_tool_runs(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Compare');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'قارن هذا الشهر بالشهر الماضي',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertContains('compare.periods', $response->json('data.tools_used'));
        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'compare.periods');
        $this->assertTrue((bool) ($fact['available'] ?? false));
        $this->assertArrayHasKey('current', $fact['data']);
        $this->assertArrayHasKey('previous', $fact['data']);
    }

    public function test_unsupported_period_is_blocked_without_silent_fallback(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Period Block');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعات سنة 2050؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame([], $response->json('data.tools_used'));
        $fact = $response->json('data.facts.0');
        $this->assertSame('period_not_supported', $fact['reason'] ?? null);
        $this->assertSame('2050-01-01', $fact['data']['requested_period']['from'] ?? null);
        $this->assertSame('2050-12-31', $fact['data']['requested_period']['to'] ?? null);
    }

    public function test_product_profit_question_is_blocked(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Product Profit');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم ربح منتج البرغر؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame([], $response->json('data.tools_used'));
        $fact = $response->json('data.facts.0');
        $this->assertSame('product_profit_not_available', $fact['reason'] ?? null);
        $this->assertStringContainsString('غير متاح', $response->json('data.answer'));
    }

    public function test_tenant_isolation_does_not_leak_other_workspace_sales(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('Tenant A');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Tenant B');
        $customerA = $this->customer($workspaceA, 'A Only');
        $this->issueSales($workspaceA, $customerA, (int) $ownerA->id, '9000.00');
        Sanctum::actingAs($ownerB);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا هذا الشهر؟',
        ], $this->workspaceHeader($workspaceB))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'sales.period');
        $this->assertSame('0.00', $fact['data']['value'] ?? null);
    }

    public function test_finance_summary_excludes_payroll_without_payroll_view(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Summary Payroll RBAC');
        $customer = $this->customer($workspace, 'Summary Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '6000.00');
        $employee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'full_name' => 'Hidden Payroll Staff',
            'employee_code' => 'FEMP-SUMMARY-RBAC',
            'status' => 'active',
            'basic_salary' => 2500,
            'created_by' => $owner->id,
        ]);
        FinanceEmployeePayrollRecord::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'finance_employee_id' => $employee->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'basic_salary' => 2500,
            'allowances_total' => 0,
            'deductions_total' => 0,
            'gross_amount' => 2500,
            'net_amount' => 2500,
            'payment_status' => 'paid',
            'paid_at' => now(),
            'created_by' => $owner->id,
        ]);
        $financeUser = $this->attachAgentWithPermissions($workspace, ['finance.view']);
        Sanctum::actingAs($financeUser);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'ملخص هذا الشهر',
        ], $this->workspaceHeader($workspace))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'finance.summary');
        $this->assertTrue((bool) ($fact['available'] ?? false));
        $this->assertSame('6000.00', $fact['data']['revenue'] ?? null);
        $this->assertArrayNotHasKey('payroll_paid_total', $fact['data'] ?? []);
        $this->assertArrayNotHasKey('payroll_metric_kind', $fact['data'] ?? []);
        $this->assertArrayNotHasKey('payroll_label_ar', $fact['data'] ?? []);
        $this->assertStringNotContainsString('2500.00', $response->json('data.answer') ?? '');
        $this->assertStringNotContainsString('الرواتب المدفوعة', $response->json('data.answer') ?? '');
    }

    public function test_finance_summary_includes_payroll_with_payroll_view(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Summary Payroll Allowed');
        $employee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'full_name' => 'Visible Payroll Staff',
            'employee_code' => 'FEMP-SUMMARY-ALLOW',
            'status' => 'active',
            'basic_salary' => 1800,
            'created_by' => $owner->id,
        ]);
        FinanceEmployeePayrollRecord::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'finance_employee_id' => $employee->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'basic_salary' => 1800,
            'allowances_total' => 0,
            'deductions_total' => 0,
            'gross_amount' => 1800,
            'net_amount' => 1800,
            'payment_status' => 'paid',
            'paid_at' => now(),
            'created_by' => $owner->id,
        ]);
        $financePayrollUser = $this->attachAgentWithPermissions($workspace, ['finance.view', 'payroll.view']);
        Sanctum::actingAs($financePayrollUser);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'ملخص هذا الشهر',
        ], $this->workspaceHeader($workspace))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'finance.summary');
        $this->assertSame('1800.00', $fact['data']['payroll_paid_total'] ?? null);
        $this->assertStringContainsString('1800.00', $response->json('data.answer') ?? '');
    }

    public function test_finance_summary_payroll_is_period_scoped(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Summary Payroll');
        $employee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'full_name' => 'Summary Staff',
            'employee_code' => 'FEMP-SUMMARY-1',
            'status' => 'active',
            'basic_salary' => 2000,
            'created_by' => $owner->id,
        ]);
        FinanceEmployeePayrollRecord::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'finance_employee_id' => $employee->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'basic_salary' => 2000,
            'allowances_total' => 0,
            'deductions_total' => 0,
            'gross_amount' => 2000,
            'net_amount' => 2000,
            'payment_status' => 'paid',
            'paid_at' => now(),
            'created_by' => $owner->id,
        ]);
        FinanceEmployeePayrollRecord::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'finance_employee_id' => $employee->id,
            'period_start' => now()->subMonthNoOverflow()->startOfMonth(),
            'period_end' => now()->subMonthNoOverflow()->endOfMonth(),
            'basic_salary' => 900,
            'allowances_total' => 0,
            'deductions_total' => 0,
            'gross_amount' => 900,
            'net_amount' => 900,
            'payment_status' => 'paid',
            'paid_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(5),
            'created_by' => $owner->id,
        ]);
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'ملخص هذا الشهر',
        ], $this->workspaceHeader($workspace))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'finance.summary');
        $this->assertSame('2000.00', $fact['data']['payroll_paid_total'] ?? null);
        $this->assertSame('paid_payroll_period', $fact['data']['payroll_metric_kind'] ?? null);
    }

    public function test_reports_only_user_can_access_sales_tools_but_not_finance_tools(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Reports Only');
        $customer = $this->customer($workspace, 'Reports Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '4200.00');
        $reportsUser = $this->attachAgentWithPermissions($workspace, ['reports.view']);
        Sanctum::actingAs($reportsUser);

        $sales = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا هذا الشهر؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $salesFact = collect($sales->json('data.facts'))->firstWhere('tool', 'sales.period');
        $this->assertTrue((bool) ($salesFact['available'] ?? false));
        $this->assertSame('4200.00', $salesFact['data']['value'] ?? null);

        $profit = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم صافي ربحنا؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $profitFact = collect($profit->json('data.facts'))->firstWhere('tool', 'profit.net');
        $this->assertFalse((bool) ($profitFact['available'] ?? true));
        $this->assertSame('permission_denied', $profitFact['reason'] ?? null);

        $expenses = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مصاريفنا؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $expensesFact = collect($expenses->json('data.facts'))->firstWhere('tool', 'expenses.period');
        $this->assertSame('permission_denied', $expensesFact['reason'] ?? null);

        $customers = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'أكثر العملاء',
        ], $this->workspaceHeader($workspace))->assertOk();
        $customersFact = collect($customers->json('data.facts'))->firstWhere('tool', 'customers.top');
        $this->assertTrue((bool) ($customersFact['available'] ?? false));

        $payables = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم علينا للموردين؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $payablesFact = collect($payables->json('data.facts'))->firstWhere('tool', 'suppliers.payable');
        $this->assertSame('permission_denied', $payablesFact['reason'] ?? null);
    }

    public function test_payroll_only_user_can_access_payroll_but_not_sales_or_profit(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Payroll Only');
        $employee = FinanceEmployee::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'full_name' => 'Payroll Only Staff',
            'employee_code' => 'FEMP-PAYONLY-1',
            'status' => 'active',
            'basic_salary' => 800,
            'created_by' => $owner->id,
        ]);
        FinanceEmployeePayrollRecord::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'finance_employee_id' => $employee->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'basic_salary' => 800,
            'allowances_total' => 0,
            'deductions_total' => 0,
            'gross_amount' => 800,
            'net_amount' => 800,
            'payment_status' => 'paid',
            'paid_at' => now(),
            'created_by' => $owner->id,
        ]);
        $payrollUser = $this->attachAgentWithPermissions($workspace, ['payroll.view']);
        Sanctum::actingAs($payrollUser);

        $payroll = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم الرواتب هذا الشهر؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $payrollFact = collect($payroll->json('data.facts'))->firstWhere('tool', 'payroll.period');
        $this->assertTrue((bool) ($payrollFact['available'] ?? false));
        $this->assertSame('800.00', $payrollFact['data']['value'] ?? null);

        $sales = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا هذا الشهر؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $salesFact = collect($sales->json('data.facts'))->firstWhere('tool', 'sales.period');
        $this->assertSame('permission_denied', $salesFact['reason'] ?? null);
    }

    public function test_expense_module_total_and_ledger_profit_can_diverge_for_draft_expenses(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Expense Semantics');
        $this->postDraftExpense($workspace, (int) $owner->id, '3000.00');
        Sanctum::actingAs($owner);

        $expenses = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مصاريفنا هذا الشهر؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $expensesFact = collect($expenses->json('data.facts'))->firstWhere('tool', 'expenses.period');
        $this->assertSame('3000.00', $expensesFact['data']['value'] ?? null);
        $this->assertSame('expense_module_total', $expensesFact['data']['metric_kind'] ?? null);

        $profit = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم صافي ربحنا هذا الشهر؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $profitFact = collect($profit->json('data.facts'))->firstWhere('tool', 'profit.net');
        $this->assertSame('0.00', $profitFact['data']['value'] ?? null);
        $this->assertSame('ledger_net_profit', $profitFact['data']['metric_kind'] ?? null);
    }

    public function test_approved_expense_module_and_ledger_profit_align_when_posted(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Expense Align');
        $customer = $this->customer($workspace, 'Align Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        $this->postExpense($workspace, (int) $owner->id, '1200.00');
        Sanctum::actingAs($owner);

        $expenses = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مصاريفنا هذا الشهر؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $expensesFact = collect($expenses->json('data.facts'))->firstWhere('tool', 'expenses.period');
        $this->assertSame('1200.00', $expensesFact['data']['value'] ?? null);

        $profit = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم صافي ربحنا هذا الشهر؟',
        ], $this->workspaceHeader($workspace))->assertOk();
        $profitFact = collect($profit->json('data.facts'))->firstWhere('tool', 'profit.net');
        $this->assertSame('3800.00', $profitFact['data']['value'] ?? null);
    }

    public function test_orchestrator_accepts_five_tools_and_rejects_six(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Max Tools Orchestrator');
        app(WorkspaceContext::class)->set($workspace);

        $fiveTools = [
            ['name' => 'sales.period', 'arguments' => ['preset' => 'month']],
            ['name' => 'expenses.period', 'arguments' => ['preset' => 'month']],
            ['name' => 'profit.net', 'arguments' => ['preset' => 'month']],
            ['name' => 'profit.gross', 'arguments' => ['preset' => 'month']],
            ['name' => 'profit.cogs', 'arguments' => ['preset' => 'month']],
        ];
        $sixTools = [...$fiveTools, ['name' => 'inventory.value', 'arguments' => []]];

        config(['finance_assistant.max_tools_per_request' => 5]);

        $orchestrator = app(FinanceAssistantOrchestrator::class);
        $accepted = $orchestrator->askWithPlans($workspace, $owner, 'max tools acceptance', $fiveTools);
        $this->assertCount(5, $accepted['facts'] ?? []);
        $this->assertCount(5, $accepted['tools_used'] ?? []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many tools requested for one question.');
        $orchestrator->askWithPlans($workspace, $owner, 'max tools rejection', $sixTools);
    }

    public function test_api_rejects_more_than_max_tools_via_compound_question(): void
    {
        config(['finance_assistant.max_tools_per_request' => 2]);

        [$owner, $workspace] = $this->createWorkspaceOwner('API Max Tools');
        Sanctum::actingAs($owner);

        $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا هذا الشهر ومصاريفنا وصافي ربحنا؟',
        ], $this->workspaceHeader($workspace))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('data.facts');
    }

    public function test_payroll_permission_is_required_for_payroll_questions(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('RBAC Payroll');
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        Permission::findOrCreate('finance.view', 'web');
        Permission::findOrCreate('payroll.view', 'web');
        $agent = User::factory()->create();
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::findOrCreate('accountant', 'web');
        $role->syncPermissions(['finance.view']);
        $agent->assignRole($role);
        Sanctum::actingAs($agent);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم الرواتب هذا الشهر؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'payroll.period');
        $this->assertFalse((bool) ($fact['available'] ?? true));
        $this->assertSame('permission_denied', $fact['reason'] ?? null);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Finance Assistant Co', bool $enableAi = true): array
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
            [
                'workspace_id' => $workspace->id,
                'feature_key' => 'ai',
                'enabled' => $enableAi,
                'source' => 'manual',
            ]
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

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
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

    private function issueSales(Workspace $workspace, Customer $customer, int $actorId, string $amount): FinanceInvoice
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(InvoiceService::class)->create($workspace, [
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
        ], $actorId);
    }

    private function postExpense(Workspace $workspace, int $actorId, string $amount): void
    {
        app(WorkspaceContext::class)->set($workspace);
        app(ExpenseService::class)->create($workspace, [
            'expense_date' => now()->toDateString(),
            'description' => 'Assistant expense',
            'amount' => $amount,
            'tax_rate' => 0,
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'status' => 'approved',
            'payment_method' => 'cash',
        ], $actorId);
    }

    private function postDraftExpense(Workspace $workspace, int $actorId, string $amount): FinanceExpense
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(ExpenseService::class)->create($workspace, [
            'expense_date' => now()->toDateString(),
            'description' => 'Assistant draft expense',
            'amount' => $amount,
            'tax_rate' => 0,
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'status' => 'draft',
            'payment_method' => 'cash',
        ], $actorId);
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

        $role = Role::findOrCreate('assistant_test_'.md5(implode(',', $permissions)), 'web');
        $role->syncPermissions($permissions);
        $agent->assignRole($role);

        return $agent;
    }
}
