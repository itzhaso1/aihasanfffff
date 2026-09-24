<?php

namespace Tests\Feature\Feature\Cashier;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\PosCashierInvoice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Cashier\Assistant\CashierAssistantToolExecutor;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CashierAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cashier_assistant.planner' => 'heuristic']);
        RateLimiter::clear('mobile-ai');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->seed(FoundationSeeder::class);
        [, $workspace] = $this->createCashierWorkspace();

        $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], ['X-Workspace-Id' => (string) $workspace->id])
            ->assertUnauthorized();
    }

    public function test_non_member_cannot_access_assistant(): void
    {
        $this->seed(FoundationSeeder::class);
        [, $workspaceA] = $this->createCashierWorkspace();
        [, $workspaceB] = $this->createCashierWorkspace('store-b');

        $outsider = User::factory()->create(['password' => bcrypt('password')]);
        Sanctum::actingAs($outsider);

        $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], ['X-Workspace-Id' => (string) $workspaceA->id])
            ->assertForbidden();
    }

    public function test_assistant_requires_ai_feature(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace(enableAi: false);
        $headers = $this->cashierHeaders($owner, $workspace);

        $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], $headers)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_sales_today_returns_structured_facts(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();
        $headers = $this->cashierHeaders($owner, $workspace);

        PosCashierInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_number' => 'CASH-1001',
            'status' => 'closed',
            'currency' => 'SAR',
            'subtotal' => 100,
            'discount_amount' => 0,
            'total_amount' => 115,
            'closed_at' => now(),
            'metadata' => [],
        ]);

        $response = $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
            'locale' => 'ar',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['answer', 'facts', 'tools_used', 'period'],
            ]);

        $this->assertContains('sales.today', $response->json('data.tools_used'));
        $facts = $response->json('data.facts') ?? [];
        $salesFact = collect($facts)->firstWhere('tool', 'sales.today');
        $this->assertTrue((bool) ($salesFact['available'] ?? false));
        $this->assertSame('115.00', $salesFact['data']['value'] ?? null);
        $this->assertSame('SAR', $salesFact['data']['currency'] ?? null);
        $this->assertNotEmpty($response->json('data.answer'));
    }

    public function test_sales_period_and_top_products_tools(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();
        $headers = $this->cashierHeaders($owner, $workspace);

        $invoice = PosCashierInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_number' => 'CASH-2001',
            'status' => 'closed',
            'currency' => 'SAR',
            'subtotal' => 50,
            'discount_amount' => 0,
            'total_amount' => 50,
            'closed_at' => now(),
            'metadata' => [],
        ]);

        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency' => 'SAR',
            'subtotal' => 50,
            'total_amount' => 50,
            'placed_at' => now(),
            'pos_cashier_invoice_id' => $invoice->id,
            'metadata' => ['payment_method' => 'cash'],
        ]);

        OrderItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'product_name' => 'قهوة',
            'quantity' => 3,
            'unit_price' => 10,
            'total_amount' => 30,
        ]);

        $period = $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'كم بعنا هذا الشهر؟',
        ], $headers)->assertOk();

        $this->assertContains('sales.period', $period->json('data.tools_used'));

        $top = $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'شو أكثر المنتجات مبيعًا؟',
        ], $headers)->assertOk();

        $this->assertContains('sales.top_products', $top->json('data.tools_used'));
        $fact = collect($top->json('data.facts'))->firstWhere('tool', 'sales.top_products');
        $this->assertSame('قهوة', $fact['data']['items'][0]['product'] ?? null);
        $this->assertSame(3, $fact['data']['items'][0]['quantity'] ?? null);
    }

    public function test_payment_methods_tool(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();
        $headers = $this->cashierHeaders($owner, $workspace);

        $invoice = PosCashierInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_number' => 'CASH-3001',
            'status' => 'closed',
            'currency' => 'SAR',
            'subtotal' => 20,
            'discount_amount' => 0,
            'total_amount' => 20,
            'closed_at' => now(),
            'metadata' => ['payment_method' => 'cash'],
        ]);

        Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency' => 'SAR',
            'subtotal' => 20,
            'total_amount' => 20,
            'placed_at' => now(),
            'pos_cashier_invoice_id' => $invoice->id,
            'metadata' => ['payment_method' => 'cash'],
        ]);

        $response = $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'كم المبيعات كاش مقابل بطاقة؟',
        ], $headers)->assertOk();

        $this->assertContains('sales.payment_methods', $response->json('data.tools_used'));
        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'sales.payment_methods');
        $this->assertTrue((bool) ($fact['available'] ?? false));
        $this->assertNotEmpty($fact['data']['items'] ?? []);
    }

    public function test_finance_and_inventory_tools_for_owner(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();
        $headers = $this->cashierHeaders($owner, $workspace);

        Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'منتج منخفض',
            'slug' => 'low-stock-product',
            'sku' => 'LOW-1',
            'status' => 'active',
            'inventory_tracking' => true,
            'stock' => 2,
            'price' => 10,
            'currency' => 'SAR',
        ]);

        foreach ([
            'كم المصاريف؟' => 'expenses.period',
            'كم صافي الربح؟' => 'profit.net',
            'كم قيمة المخزون؟' => 'inventory.value',
            'شو المنتجات اللي قربت تخلص؟' => 'inventory.low_stock',
            'شو الأشياء اللي تحتاج انتباه؟' => 'business.alerts',
        ] as $question => $tool) {
            $response = $this->postJson('/api/cashier/v1/assistant/ask', [
                'question' => $question,
            ], $headers)->assertOk();

            $this->assertContains($tool, $response->json('data.tools_used'), "Failed asserting tool for question: {$question}");
        }
    }

    public function test_agent_gets_permission_denied_for_restricted_tools(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();
        $agent = User::factory()->create(['password' => bcrypt('password')]);
        $workspace->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        $agent->assignRole(Role::findByName('agent', 'web'));

        $headers = $this->cashierHeaders($agent, $workspace);

        $response = $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], $headers)->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'sales.today');
        $this->assertFalse((bool) ($fact['available'] ?? true));
        $this->assertSame('permission_denied', $fact['reason'] ?? null);
    }

    public function test_unsupported_question_does_not_invent_numbers(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();
        $headers = $this->cashierHeaders($owner, $workspace);

        $response = $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'ما هو لون السماء؟',
        ], $headers)->assertOk();

        $this->assertSame([], $response->json('data.tools_used'));
        $answer = (string) $response->json('data.answer');
        $this->assertDoesNotMatchRegularExpression('/\d/', $answer);
    }

    public function test_too_many_tools_rejected(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();
        config(['cashier_assistant.max_tools_per_request' => 2]);
        $headers = $this->cashierHeaders($owner, $workspace);

        $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'أعطني مبيعات هذا الشهر ومصروفات هذا الشهر وصافي الربح',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_invalid_tool_name_rejected_by_executor(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();

        $this->expectException(InvalidArgumentException::class);

        app(CashierAssistantToolExecutor::class)->execute($workspace, $owner, [
            'name' => 'sales.nonexistent',
            'arguments' => [],
        ]);
    }

    public function test_validation_rejects_missing_question(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createCashierWorkspace();
        $headers = $this->cashierHeaders($owner, $workspace);

        $this->postJson('/api/cashier/v1/assistant/ask', [], $headers)
            ->assertUnprocessable();
    }

    public function test_cross_workspace_data_isolation_via_scoped_queries(): void
    {
        $this->seed(FoundationSeeder::class);
        [$ownerA, $workspaceA] = $this->createCashierWorkspace();
        [, $workspaceB] = $this->createCashierWorkspace('store-b');

        PosCashierInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'invoice_number' => 'CASH-B-1',
            'status' => 'closed',
            'currency' => 'SAR',
            'subtotal' => 999,
            'discount_amount' => 0,
            'total_amount' => 999,
            'closed_at' => now(),
            'metadata' => [],
        ]);

        PosCashierInvoice::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'invoice_number' => 'CASH-A-1',
            'status' => 'closed',
            'currency' => 'SAR',
            'subtotal' => 10,
            'discount_amount' => 0,
            'total_amount' => 10,
            'closed_at' => now(),
            'metadata' => [],
        ]);

        $headers = $this->cashierHeaders($ownerA, $workspaceA);
        $response = $this->postJson('/api/cashier/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
        ], $headers)->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'sales.today');
        $this->assertSame('10.00', $fact['data']['value'] ?? null);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCashierWorkspace(string $suffix = 'store', bool $enableAi = true): array
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'store',
            'name' => 'Cashier Assistant '.$suffix,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['pos', 'qr_menu', 'products', 'orders', 'finance'] as $feature) {
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

        $plan = Plan::query()
            ->where('workspace_type', 'store')
            ->where('is_active', true)
            ->orderByDesc('price')
            ->first();

        if ($plan) {
            Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        return [$user, $workspace];
    }

    /**
     * @return array<string, string>
     */
    private function cashierHeaders(User $user, Workspace $workspace): array
    {
        Sanctum::actingAs($user);
        app(WorkspaceContext::class)->set($workspace);

        return [
            'Authorization' => 'Bearer '.$user->createToken('cashier-test')->plainTextToken,
            'X-Workspace-Id' => (string) $workspace->id,
        ];
    }
}
