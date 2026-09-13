<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceFlutterFeatureParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_hubs_catalog_and_ops_modules_are_exposed_to_finance_client(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $this->withHeaders($headers)->getJson('/api/finance/v1/sales')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['summary' => ['total_sales', 'unpaid_count'], 'invoices', 'recent_payments']]);

        $this->withHeaders($headers)->getJson('/api/finance/v1/billing')->assertOk()
            ->assertJsonStructure(['data' => ['total_invoices', 'outstanding_amount', 'due_today']]);

        $this->withHeaders($headers)->getJson('/api/finance/v1/vat')->assertOk()
            ->assertJsonStructure(['data' => ['output', 'input', 'net', 'rates']]);

        $this->withHeaders($headers)->getJson('/api/finance/v1/alerts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/finance/v1/accounting')->assertOk()
            ->assertJsonStructure(['data' => ['accounts', 'entries', 'trial_balance', 'trial_totals', 'monthly_cash_flow']]);
        $this->withHeaders($headers)->getJson('/api/finance/v1/banks')->assertOk();
        $this->withHeaders($headers)->getJson('/api/finance/v1/treasury')->assertOk()
            ->assertJsonStructure(['data' => ['accounts', 'transfers', 'statements']]);
        $this->withHeaders($headers)->getJson('/api/finance/v1/exports')->assertOk();
        $this->assertSame('invoices', $this->withHeaders($headers)->getJson('/api/finance/v1/exports')->json('data.0.dataset'));
        $this->withHeaders($headers)->getJson('/api/finance/v1/fiscal-years')->assertOk();
        $this->withHeaders($headers)->getJson('/api/finance/v1/inventory')->assertOk();
    }

    public function test_products_projects_leads_price_lists_and_purchase_orders_round_trip(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $this->withHeaders($headers)->getJson('/api/finance/v1/bootstrap')->assertOk();
        app(WorkspaceContext::class)->set($workspace);

        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Widget Parity',
            'slug' => 'widget-parity-'.uniqid(),
            'sku' => 'SKU-PARITY-1',
            'price' => 50,
            'currency' => 'SAR',
            'stock' => 12,
            'inventory_tracking' => true,
            'status' => 'active',
        ]);

        $this->withHeaders($headers)->getJson('/api/finance/v1/products')->assertOk()
            ->assertJsonPath('data.0.name', 'Widget Parity')
            ->assertJsonPath('data.0.sold_total', '0.00');
        $this->withHeaders($headers)->getJson('/api/finance/v1/products/'.$product->id)->assertOk()
            ->assertJsonPath('data.sku', 'SKU-PARITY-1');

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Project Buyer',
            'phone' => '0501112233',
            'email' => 'project.buyer@example.com',
        ]);

        $project = $this->withHeaders($headers)->postJson('/api/finance/v1/projects', [
            'name' => 'مشروع التكافؤ',
            'customer_id' => $customer->id,
            'budget' => 1000,
        ])->assertCreated()->json('data');
        $this->assertSame('مشروع التكافؤ', $project['name']);
        $this->assertSame('0.00', $project['profit']);

        $invoice = $this->withHeaders($headers)->postJson('/api/finance/v1/sales-invoices', [
            'customer_id' => $customer->id,
            'project_id' => $project['id'],
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'draft',
            'tax_document_subtype' => 'simplified',
            'zatca_requirement' => 'not_required',
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
                'exemption_reason' => '',
            ]],
        ])->assertCreated();
        $this->assertSame($project['id'], $invoice->json('data.project_id'));
        $this->assertSame($product->id, $invoice->json('data.lines.0.product_id'));

        $walkIn = $this->withHeaders($headers)->postJson('/api/finance/v1/sales-invoices', [
            'customer_name' => 'عميل نقدي',
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'draft',
            'items' => [[
                'product_name' => 'بيع نقدي',
                'quantity' => 1,
                'unit_price' => 20,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ])->assertCreated();
        $this->assertSame('عميل نقدي', $walkIn->json('data.customer_name'));

        $invoiceId = $invoice->json('data.id');
        $this->withHeaders($headers)
            ->post('/api/finance/v1/sales-invoices/'.$invoiceId.'/attachments', [
                'attachments' => [UploadedFile::fake()->create('note.pdf', 12, 'application/pdf')],
            ])
            ->assertOk()
            ->assertJsonPath('data.attachments.0.file_name', 'note.pdf');

        $lead = $this->withHeaders($headers)->postJson('/api/finance/v1/leads', [
            'name' => 'عميل محتمل',
            'company_name' => 'شركة تجريبية',
            'email' => 'lead@example.com',
        ])->assertCreated()->json('data');
        $this->withHeaders($headers)->getJson('/api/finance/v1/leads/'.$lead['id'])
            ->assertOk()
            ->assertJsonPath('data.name', 'عميل محتمل');
        $converted = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/leads/'.$lead['id'].'/convert')
            ->assertOk();
        $this->assertNotEmpty($converted->json('data.customer.id'));
        $this->assertSame('converted', $converted->json('data.lead.status'));

        $list = $this->withHeaders($headers)->postJson('/api/finance/v1/price-lists', [
            'name' => 'قائمة التكافؤ',
            'currency' => 'SAR',
        ])->assertCreated()->json('data');
        $this->withHeaders($headers)->postJson('/api/finance/v1/price-lists/'.$list['id'].'/items', [
            'product_id' => $product->id,
            'price' => 75,
            'tax_rate' => 15,
        ])->assertCreated();
        $this->withHeaders($headers)->postJson('/api/finance/v1/price-lists/'.$list['id'].'/approve')->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $supplier = $this->withHeaders($headers)->postJson('/api/finance/v1/suppliers', [
            'name' => 'مورد التكافؤ',
        ])->assertCreated()->json('data');
        $po = $this->withHeaders($headers)->postJson('/api/finance/v1/purchase-orders', [
            'supplier_id' => $supplier['id'],
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => 2,
                'unit_price' => 10,
                'tax_rate' => 15,
            ]],
        ])->assertCreated();
        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/purchase-orders/'.$po->json('data.id').'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->withHeaders($headers)->getJson('/api/finance/v1/search?q='.urlencode('Widget'))
            ->assertOk();
        $this->assertNotEmpty($this->withHeaders($headers)->getJson('/api/finance/v1/search?q='.urlencode('Widget'))->json('data.products'));
        $this->assertNotEmpty($this->withHeaders($headers)->getJson('/api/finance/v1/search?q='.urlencode('مشروع'))->json('data.projects'));

        $this->withHeaders($headers)->postJson('/api/finance/v1/copilot/ask', [
            'question' => 'مبيعات هذا الشهر',
        ])->assertOk()->assertJsonPath('success', true);

        $nextYear = now()->addYear()->year;
        $this->withHeaders($headers)->postJson('/api/finance/v1/fiscal-years', [
            'name' => (string) $nextYear,
            'start_date' => $nextYear.'-01-01',
            'end_date' => $nextYear.'-12-31',
        ])->assertCreated()->assertJsonPath('data.name', (string) $nextYear);

        $this->withHeaders($headers)->putJson('/api/finance/v1/settings', [
            'invoice_prefix' => 'INV',
            'invoice_primary_color' => '#06C2A4',
            'invoice_footer_text' => 'شكراً لتعاملكم معنا',
            'allow_manual_invoice_numbers' => true,
            'country_code' => 'SA',
        ])->assertOk()
            ->assertJsonPath('data.allow_manual_invoice_numbers', true)
            ->assertJsonPath('data.invoice_primary_color', '#06C2A4')
            ->assertJsonPath('data.invoice_footer_text', 'شكراً لتعاملكم معنا');

        $this->withHeaders($headers)->getJson('/api/finance/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['analytics' => ['from', 'to', 'hero', 'attention', 'top_customers']]]);

        $this->withHeaders($headers)->getJson('/api/finance/v1/purchases?supplier_id='.$supplier['id'])
            ->assertOk();
    }

    public function test_contract_attachments_logo_dashboard_filters_and_bank_matching(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Attachment Workspace');
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $this->withHeaders($headers)->getJson('/api/finance/v1/bootstrap')->assertOk();
        app(WorkspaceContext::class)->set($workspace);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل المرفقات',
            'phone' => '0509998877',
            'email' => 'attachments@example.com',
        ]);

        $contract = $this->withHeaders($headers)->postJson('/api/finance/v1/contracts', [
            'title' => 'عقد المرفقات',
            'customer_id' => $customer->id,
            'value' => 500,
            'currency' => 'SAR',
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data');

        $upload = $this->withHeaders($headers)
            ->post('/api/finance/v1/contracts/'.$contract['id'].'/attachments', [
                'attachments' => [UploadedFile::fake()->create('contract-scan.pdf', 18, 'application/pdf')],
            ])
            ->assertOk();
        $this->assertSame('contract-scan.pdf', $upload->json('data.attachments.0.file_name'));
        $attachmentId = $upload->json('data.attachments.0.id');

        $this->withHeaders($headers)
            ->get('/api/finance/v1/contracts/'.$contract['id'].'/attachments/'.$attachmentId)
            ->assertOk();

        $this->withHeaders($headers)
            ->deleteJson('/api/finance/v1/contracts/'.$contract['id'].'/attachments/'.$attachmentId)
            ->assertOk()
            ->assertJsonPath('data.attachments', []);

        $this->withHeaders($headers)->postJson('/api/finance/v1/contracts/'.$contract['id'].'/activate')->assertOk();
        $this->withHeaders($headers)->postJson('/api/finance/v1/contracts/'.$contract['id'].'/close')->assertOk();
        $this->withHeaders($headers)
            ->post('/api/finance/v1/contracts/'.$contract['id'].'/attachments', [
                'attachments' => [UploadedFile::fake()->create('late.pdf', 8, 'application/pdf')],
            ])
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->post('/api/finance/v1/settings/logo', [
                'logo' => UploadedFile::fake()->image('company.png', 80, 80),
            ])
            ->assertOk()
            ->assertJsonPath('data.has_logo', true);
        $this->assertNotEmpty($this->withHeaders($headers)->getJson('/api/finance/v1/settings')->json('data.logo_url'));
        $this->withHeaders($headers)->get('/api/finance/v1/settings/logo')->assertOk();
        $this->withHeaders($headers)->deleteJson('/api/finance/v1/settings/logo')
            ->assertOk()
            ->assertJsonPath('data.has_logo', false);

        $settings = $this->withHeaders($headers)->getJson('/api/finance/v1/settings')
            ->assertOk()
            ->json('data');
        $this->assertIsArray($settings['tax_rates']);
        $this->assertIsArray($settings['treasury_accounts']);
        $this->assertIsArray($settings['finance_accounts']);

        $this->withHeaders($headers)->postJson('/api/finance/v1/settings/tax-rates', [
            'name' => 'VAT Extra',
            'code' => 'VAT_X',
            'type' => 'standard',
            'rate' => 15,
            'is_default' => true,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.code', 'VAT_X');

        $cash = $this->withHeaders($headers)->postJson('/api/finance/v1/settings/treasury-accounts', [
            'name' => 'صندوق المكتب',
            'type' => 'cash',
            'currency' => 'SAR',
            'opening_balance' => 50,
            'current_balance' => 50,
        ])->assertOk()->json('data');
        $this->assertSame('cash', $cash['type']);

        $settings = $this->withHeaders($headers)->getJson('/api/finance/v1/settings')->assertOk()->json('data');
        $this->assertTrue(collect($settings['tax_rates'])->contains(fn ($row) => ($row['code'] ?? '') === 'VAT_X'));
        $this->assertTrue(collect($settings['treasury_accounts'])->contains(fn ($row) => ($row['name'] ?? '') === 'صندوق المكتب'));

        $this->withHeaders($headers)->getJson('/api/finance/v1/dashboard?'.http_build_query([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lifecycle' => 'sent',
            'payment_method' => 'cash',
        ]))->assertOk()->assertJsonPath('success', true);

        $treasury = $this->withHeaders($headers)->getJson('/api/finance/v1/treasury')->assertOk()->json('data');
        $accountId = $treasury['accounts'][0]['id'] ?? null;
        if (! $accountId) {
            $accountId = $this->withHeaders($headers)->postJson('/api/finance/v1/settings/treasury-accounts', [
                'name' => 'بنك الاختبار',
                'type' => 'bank',
                'currency' => 'SAR',
            ])->assertOk()->json('data.id');
        }
        $this->assertNotEmpty($accountId);

        $statement = $this->withHeaders($headers)->postJson('/api/finance/v1/treasury/statements', [
            'treasury_account_id' => $accountId,
            'statement_date' => now()->toDateString(),
            'opening_balance' => 0,
            'closing_balance' => 100,
            'notes' => 'كشف اختبار',
        ])->assertCreated()->json('data');

        $withLines = $this->withHeaders($headers)->postJson('/api/finance/v1/treasury/statements/'.$statement['id'].'/lines', [
            'lines' => [[
                'posted_date' => now()->toDateString(),
                'amount' => 100,
                'description' => 'إيداع',
                'reference' => 'DEP-1',
            ]],
        ])->assertOk()->json('data');
        $lineId = $withLines['lines'][0]['id'];

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/treasury/statements/'.$statement['id'].'/suggest')
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/treasury/statements/'.$statement['id'].'/lines/'.$lineId.'/ignore')
            ->assertOk()
            ->assertJsonPath('data.lines.0.status', 'ignored');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/treasury/statements/'.$statement['id'].'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/treasury/statements/'.$statement['id'].'/lines', [
                'lines' => [['amount' => 10, 'description' => 'بعد الإغلاق']],
            ])
            ->assertStatus(422);

        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Other Attachment Co');
        Sanctum::actingAs($ownerB);
        $this->withHeaders($this->workspaceHeader($workspaceB))
            ->getJson('/api/finance/v1/contracts/'.$contract['id'])
            ->assertStatus(404);
        $this->withHeaders($this->workspaceHeader($workspaceB))
            ->getJson('/api/finance/v1/treasury/statements/'.$statement['id'])
            ->assertStatus(404);
    }

    public function test_feature_endpoints_respect_workspace_and_permissions(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('Alpha Co');
        Sanctum::actingAs($ownerA);
        $headersA = $this->workspaceHeader($workspaceA);
        $projectId = $this->withHeaders($headersA)->postJson('/api/finance/v1/projects', [
            'name' => 'سري',
        ])->assertCreated()->json('data.id');

        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Beta Co');
        Sanctum::actingAs($ownerB);
        $this->withHeaders($this->workspaceHeader($workspaceB))
            ->getJson('/api/finance/v1/projects/'.$projectId)
            ->assertStatus(404);

        $agent = User::factory()->create();
        $workspaceA->users()->attach($agent->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($agent);
        $this->withHeaders($headersA)
            ->getJson('/api/finance/v1/products')
            ->assertStatus(403);
        $this->withHeaders($headersA)
            ->postJson('/api/finance/v1/copilot/ask', ['question' => 'مبيعات'])
            ->assertStatus(403);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Finance Feature Workspace'): array
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
