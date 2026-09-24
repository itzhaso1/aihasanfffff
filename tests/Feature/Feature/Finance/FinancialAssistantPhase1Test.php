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
use App\Services\Finance\Assistant\FinancialAssistantMetricRegistry;
use App\Services\Finance\Assistant\Handlers\FinancialAssistantGreetingHandler;
use App\Services\Finance\Assistant\Handlers\FinancialAssistantOffTopicHandler;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancialAssistantPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance_assistant.planner' => 'heuristic']);
        RateLimiter::clear('mobile-ai');
        $this->seed(FoundationSeeder::class);
    }

    public function test_greeting_returns_natural_response_without_tools(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'هلا',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame([], $response->json('data.tools_used'));
        $this->assertStringContainsString('مساعدك المالي', (string) $response->json('data.answer'));
        $this->assertSame('greeting', $response->json('data.intent'));
    }

    public function test_salam_greeting_is_not_blocked_by_km_substring_in_alaykum(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'السلام عليكم',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame('greeting', $response->json('data.intent'));
        $this->assertSame([], $response->json('data.tools_used'));
        $this->assertStringContainsString('وعليكم السلام', (string) $response->json('data.answer'));
        $this->assertStringContainsString('مساعدك المالي', (string) $response->json('data.answer'));
    }

    public function test_how_are_you_greeting_is_natural(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كيفك؟',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame('greeting', $response->json('data.intent'));
        $this->assertStringContainsString('بخير', (string) $response->json('data.answer'));
    }

    public function test_financial_status_question_is_not_treated_as_greeting(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Status Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '3000.00');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كيف وضعي هذا الشهر؟',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertNotSame('greeting', $response->json('data.intent'));
        $this->assertNotEmpty($response->json('data.tools_used'));
    }

    public function test_off_topic_question_redirects_to_finance_scope(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'شو رأيك بفيلم Inception؟',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $this->assertSame('off_topic', $response->json('data.intent'));
        $this->assertSame([], $response->json('data.tools_used'));
        $this->assertStringContainsString('HASEM', (string) $response->json('data.answer'));
    }

    public function test_sales_fact_includes_ledger_revenue_metric_labels(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Metric Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '1500.00');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا هذا الشهر؟',
            'locale' => 'ar',
        ], $this->workspaceHeader($workspace))->assertOk();

        $fact = collect($response->json('data.facts'))->firstWhere('tool', 'sales.period');
        $this->assertSame('ledger_revenue', $fact['data']['source'] ?? null);
        $this->assertSame('الإيراد المحاسبي', $fact['data']['metric_label_ar'] ?? null);
        $this->assertSame('Ledger revenue', $fact['data']['metric_label_en'] ?? null);
    }

    public function test_metric_registry_distinguishes_invoice_sales_from_ledger_revenue(): void
    {
        $registry = app(FinancialAssistantMetricRegistry::class);

        $this->assertNotSame(
            $registry->label(FinancialAssistantMetricRegistry::LEDGER_REVENUE, 'ar'),
            $registry->label(FinancialAssistantMetricRegistry::INVOICE_SALES_TOTAL, 'ar'),
        );

        $lines = $registry->promptLines('ar');
        $this->assertNotEmpty($lines);
        $this->assertTrue(collect($lines)->contains(fn (string $line): bool => str_contains($line, 'ledger_revenue')));
    }

    public function test_greeting_handler_unit_matches_and_rejects_financial_mix(): void
    {
        $handler = app(FinancialAssistantGreetingHandler::class);

        $this->assertSame('greeting', $handler->match('هلا')['intent'] ?? null);
        $this->assertSame('salam', $handler->match('السلام عليكم')['variant'] ?? null);
        $this->assertNull($handler->match('كم مبيعاتنا؟'));
        $this->assertNull($handler->match('كيف وضعي هذا الشهر؟'));
        $this->assertNull($handler->match('السلام عليكم كم مبيعاتنا؟'));
    }

    public function test_off_topic_handler_unit(): void
    {
        $handler = app(FinancialAssistantOffTopicHandler::class);

        $this->assertSame('off_topic', $handler->match('شو رأيك بفيلم؟')['intent'] ?? null);
        $this->assertNull($handler->match('كم علينا للموردين؟'));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Phase1 Co'): array
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

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
