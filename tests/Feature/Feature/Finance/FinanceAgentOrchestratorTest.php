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
use App\Services\AI\Contracts\GeminiInteractionsClient;
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
use Tests\Support\FakeGeminiInteractionsClient;
use Tests\TestCase;

class FinanceAgentOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private FakeGeminiInteractionsClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        RateLimiter::clear('mobile-ai');
        config([
            'finance_assistant.agent_enabled' => true,
            'finance_assistant.heuristic_planner_in_testing' => false,
            'services.google_ai_studio.key' => '',
        ]);
        $this->gemini = new FakeGeminiInteractionsClient;
        $this->app->instance(GeminiInteractionsClient::class, $this->gemini);
    }

    public function test_single_tool_round_trip(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Agent Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('i1', [
                ['name' => 'sales_today', 'arguments' => []],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('i2', 'مبيعات اليوم 5000.00 ريال.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertContains('sales.today', $response->json('data.tools_used'));
        $this->assertSame('5000.00', collect($response->json('data.facts'))->firstWhere('tool', 'sales.today')['data']['value'] ?? null);
        $this->assertStringContainsString('5000.00', (string) $response->json('data.answer'));
        $this->assertCount(2, $this->gemini->requests);
        $this->assertSame('function_result', $this->gemini->requests[1]->input[0]['type'] ?? null);
    }

    public function test_sequential_multi_tool_calls(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Seq');
        $customer = $this->customer($workspace, 'Seq Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $this->postExpense($workspace, (int) $owner->id, '2500.00');
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('s1', [
                ['name' => 'profit_net', 'arguments' => ['preset' => 'month']],
            ]))
            ->queue(FakeGeminiInteractionsClient::requiring('s2', [
                ['name' => 'compare_periods', 'arguments' => ['preset' => 'month']],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('s3', 'صافي الربح 7500.00 ريال هذا الشهر.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'ليش الربح هذا الشهر نازل؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $tools = $response->json('data.tools_used') ?? [];
        $this->assertContains('profit.net', $tools);
        $this->assertContains('compare.periods', $tools);
        $this->assertStringContainsString('7500.00', (string) $response->json('data.answer'));
        $this->assertCount(3, $this->gemini->requests);
        $this->assertSame('s1', $this->gemini->requests[1]->previousInteractionId);
    }

    public function test_parallel_function_calls_in_one_round(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Parallel');
        $customer = $this->customer($workspace, 'Parallel Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '10000.00');
        $this->postExpense($workspace, (int) $owner->id, '2500.00');
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('p1', [
                ['name' => 'sales_period', 'arguments' => ['preset' => 'month']],
                ['name' => 'expenses_period', 'arguments' => ['preset' => 'month']],
                ['name' => 'profit_net', 'arguments' => ['preset' => 'month']],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('p2', 'المبيعات 10000.00 والمصاريف 2500.00 وصافي الربح 7500.00.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا ومصاريفنا وصافي الربح؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $tools = $response->json('data.tools_used') ?? [];
        $this->assertContains('sales.period', $tools);
        $this->assertContains('expenses.period', $tools);
        $this->assertContains('profit.net', $tools);
        $this->assertCount(2, $this->gemini->requests);
        $this->assertCount(3, $this->gemini->requests[1]->input);
    }

    public function test_payroll_permission_denied_is_not_in_answer(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('RBAC');
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        Permission::findOrCreate('reports.view', 'web');
        $agent = User::factory()->create();
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::findOrCreate('reports_only_agent', 'web');
        $role->syncPermissions(['reports.view']);
        $agent->assignRole($role);
        Sanctum::actingAs($agent);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('r1', [
                ['name' => 'payroll_period', 'arguments' => ['preset' => 'month']],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('r2', 'الرواتب 1500.00 ريال.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم الرواتب؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'payroll.period');
        $this->assertFalse((bool) ($fact['available'] ?? true));
        $this->assertSame('permission_denied', $fact['reason'] ?? null);
        $this->assertStringNotContainsString('1500', (string) $response->json('data.answer'));
        $this->assertStringNotContainsString('payroll', mb_strtolower((string) $response->json('data.answer')));
    }

    public function test_workspace_id_in_gemini_arguments_is_ignored(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Tenant A');
        [, $other] = $this->createWorkspaceOwner('Tenant B');
        $customer = $this->customer($workspace, 'A Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('t1', [
                ['name' => 'sales_today', 'arguments' => ['workspace_id' => $other->id, 'tenant_id' => $other->id]],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('t2', 'مبيعات اليوم 5000.00 ريال.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame('5000.00', collect($response->json('data.facts'))->firstWhere('tool', 'sales.today')['data']['value'] ?? null);
        $resultJson = (string) ($this->gemini->requests[1]->input[0]['result'][0]['text'] ?? '');
        $this->assertStringNotContainsString('"workspace_id"', $resultJson);
        $this->assertStringNotContainsString('"tenant_id"', $resultJson);
    }

    public function test_unknown_tool_is_rejected(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('u1', [
                ['name' => 'sales_admin_export', 'arguments' => []],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('u2', 'لا أستطيع تأكيد هذا الرقم من السجلات المتاحة.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'صدّر المبيعات',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertNotContains('sales.admin_export', $response->json('data.tools_used') ?? []);
        $this->assertSame('unknown_tool', collect($response->json('data.facts'))->first()['reason'] ?? null);
    }

    public function test_invalid_arguments_are_rejected(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('a1', [
                ['name' => 'profit_net', 'arguments' => ['from' => 'not-a-date']],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('a2', 'لا أستطيع تأكيد هذا الرقم من السجلات المتاحة.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم الربح؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame('invalid_arguments', collect($response->json('data.facts'))->firstWhere('tool', 'profit.net')['reason'] ?? null);
    }

    public function test_max_rounds_stops_safely(): void
    {
        config(['finance_assistant.max_tool_rounds' => 2]);
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Loop Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('m1', [
                ['name' => 'sales_today'],
            ]))
            ->queue(FakeGeminiInteractionsClient::requiring('m2', [
                ['name' => 'sales_period', 'arguments' => ['preset' => 'month']],
            ]))
            ->queue(FakeGeminiInteractionsClient::requiring('m3', [
                ['name' => 'profit_net', 'arguments' => ['preset' => 'month']],
            ]));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'حلل كل شيء',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertNotEmpty($response->json('data.answer'));
        $this->assertLessThanOrEqual(3, count($this->gemini->requests));
        $this->assertContains('sales.today', $response->json('data.tools_used'));
        $this->assertNotContains('profit.net', $response->json('data.tools_used'));
    }

    public function test_duplicate_calls_are_not_reexecuted(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Dup Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('d1', [
                ['name' => 'sales_today', 'id' => 'c1'],
                ['name' => 'sales_today', 'id' => 'c2'],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('d2', 'مبيعات اليوم 5000.00 ريال.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعات اليوم؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $facts = collect($response->json('data.facts'))->where('tool', 'sales.today');
        $this->assertCount(1, $facts);
        $this->assertCount(2, $this->gemini->requests[1]->input);
    }

    public function test_invented_numbers_are_replaced(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Ground Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('g1', [
                ['name' => 'sales_today'],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('g2', 'المبيعات بلغت 999999 ريال.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $answer = (string) $response->json('data.answer');
        $this->assertStringNotContainsString('999999', $answer);
        $this->assertStringContainsString('5000.00', $answer);
    }

    public function test_product_profit_remains_unavailable(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم ربح منتج البرغر؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame([], $this->gemini->requests);
        $this->assertSame([], $response->json('data.tools_used'));
        $this->assertStringContainsString('غير متاح', (string) $response->json('data.answer'));
    }

    public function test_unsupported_future_period_remains_unavailable(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعات سنة 2050؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame([], $this->gemini->requests);
        $this->assertSame([], $response->json('data.tools_used'));
        $this->assertSame('period_not_supported', $response->json('data.facts.0.reason'));
    }

    public function test_expenses_by_category_tool_is_available_to_the_agent(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Cats');
        $this->postExpense($workspace, (int) $owner->id, '2500.00');
        Sanctum::actingAs($owner);

        $this->gemini
            ->queue(FakeGeminiInteractionsClient::requiring('c1', [
                ['name' => 'expenses_by_category', 'arguments' => ['preset' => 'month']],
            ]))
            ->queue(FakeGeminiInteractionsClient::completed('c2', 'أعلى بند مصاريف 2500.00 ريال.'));

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'أي بند مصاريف زاد؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertContains('expenses.by_category', $response->json('data.tools_used'));
        $items = collect($response->json('data.facts'))->firstWhere('tool', 'expenses.by_category')['data']['items'] ?? [];
        $this->assertNotEmpty($items);
        $this->assertSame('2500.00', $items[0]['value'] ?? null);
        $this->assertArrayNotHasKey('id', $items[0]);
    }

    public function test_feature_flag_keeps_legacy_planner(): void
    {
        config(['finance_assistant.agent_enabled' => false, 'finance_assistant.planner' => 'heuristic']);
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Legacy Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        Sanctum::actingAs($owner);

        $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame([], $this->gemini->requests);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Finance Agent Co', bool $enableAi = true): array
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
                'product_name' => 'Agent line',
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
            'description' => 'Agent expense',
            'amount' => $amount,
            'tax_rate' => 0,
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'status' => 'approved',
            'payment_method' => 'cash',
        ], $actorId);
    }
}
