<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\QuoteService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceBillingParityImplementationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_dashboard_sales_total_excludes_draft_and_cancelled_invoices(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $customer = $this->makeBuyer($workspace, 'Dashboard Buyer');

        app(WorkspaceContext::class)->set($workspace);
        $invoiceService = app(InvoiceService::class);
        $invoiceService->create($workspace, $this->invoiceBody($customer->id, 100), (int) $owner->id);
        $issued = $invoiceService->create($workspace, array_merge($this->invoiceBody($customer->id, 200), [
            'invoice_status' => 'issued',
        ]), (int) $owner->id);
        $cancelled = $invoiceService->create($workspace, array_merge($this->invoiceBody($customer->id, 300), [
            'invoice_status' => 'issued',
        ]), (int) $owner->id);
        $invoiceService->cancel($cancelled);

        $dashboard = $this->withHeaders($headers)->getJson('/api/finance/v1/dashboard')->assertOk();
        $this->assertSame('200.00', $dashboard->json('data.cards.sales'));
        $this->assertNotEquals('230.00', $dashboard->json('data.cards.sales'));
        $this->assertNotEquals('690.00', $dashboard->json('data.cards.sales'));
        $this->assertSame('issued', $issued->fresh()->invoice_status ?: $issued->fresh()->status);
    }

    public function test_issued_invoice_exposes_field_parity_and_foundation_zatca_without_fatoora_claim(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $this->completeSeller($workspace);
        $customer = $this->makeBuyer($workspace, 'Zatca Buyer');

        $created = $this->withHeaders($headers)
            ->postJson('/api/finance/v1/sales-invoices', array_merge($this->invoiceBody($customer->id, 100), [
                'supply_date' => now()->toDateString(),
                'invoice_status' => 'issued',
            ]))
            ->assertCreated();

        $invoiceId = (int) $created->json('data.id');
        $show = $this->withHeaders($headers)->getJson('/api/finance/v1/sales-invoices/'.$invoiceId)->assertOk();

        $this->assertNotEmpty($show->json('data.issued_at'));
        $this->assertSame(now()->toDateString(), $show->json('data.supply_date'));
        $this->assertIsArray($show->json('data.tax_breakdown'));
        $this->assertFalse((bool) $show->json('data.zatca.clearance'));
        $this->assertFalse((bool) $show->json('data.zatca.reporting'));
        $this->assertFalse((bool) $show->json('data.zatca.production_stamp'));
        $this->assertSame('foundation', $show->json('data.zatca.integration'));
        $this->assertTrue((bool) $show->json('data.zatca.xml_available'));

        $xml = $this->withHeaders($headers)->getJson('/api/finance/v1/sales-invoices/'.$invoiceId.'/xml')->assertOk();
        $this->assertNotEmpty($xml->json('data.xml'));
        $this->assertStringContainsString('Invoice', (string) $xml->json('data.root_local_name'));

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoiceId))
            ->assertOk()
            ->assertDontSee('ولا يتم توليد QR أو XML')
            ->assertSee('أساس داخلي')
            ->assertSee('تحميل XML');

        $this->assertIsArray($show->json('data.journal_entries'));
        $this->assertNotEmpty($show->json('data.journal_entries'));
    }

    public function test_invoice_inbox_sorts_and_exposes_pipeline_meta(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $customer = $this->makeBuyer($workspace, 'Inbox Buyer');

        $this->withHeaders($headers)->postJson('/api/finance/v1/sales-invoices', $this->invoiceBody($customer->id, 50))->assertCreated();
        $this->withHeaders($headers)->postJson('/api/finance/v1/sales-invoices', array_merge(
            $this->invoiceBody($customer->id, 80),
            ['invoice_status' => 'issued']
        ))->assertCreated();

        $list = $this->withHeaders($headers)
            ->getJson('/api/finance/v1/sales-invoices?sort=total&direction=desc')
            ->assertOk();

        $this->assertArrayHasKey('pipeline', $list->json('meta'));
        $this->assertArrayHasKey('totals', $list->json('meta'));
        $this->assertSame('total', $list->json('meta.sort'));
        $this->assertGreaterThanOrEqual((float) $list->json('data.1.total'), (float) $list->json('data.0.total'));
    }

    public function test_quote_attachments_round_trip_and_are_workspace_isolated(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Quote Attach A');
        [$otherOwner, $otherWorkspace] = $this->createWorkspaceOwner('Quote Attach B');
        app(WorkspaceContext::class)->set($workspace);
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $customer = $this->makeBuyer($workspace, 'Quote Customer');

        $quote = $this->withHeaders($headers)->postJson('/api/finance/v1/quotes', [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'استشارة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ])->assertCreated();

        $quoteId = (int) $quote->json('data.id');
        $upload = $this->withHeaders($headers)->post('/api/finance/v1/quotes/'.$quoteId.'/attachments', [
            'attachments' => [UploadedFile::fake()->create('quote-scan.pdf', 14, 'application/pdf')],
        ])->assertOk();
        $this->assertSame('quote-scan.pdf', $upload->json('data.attachments.0.file_name'));
        $attachmentId = (int) $upload->json('data.attachments.0.id');

        $this->withHeaders($headers)
            ->get('/api/finance/v1/quotes/'.$quoteId.'/attachments/'.$attachmentId)
            ->assertOk();

        Sanctum::actingAs($otherOwner);
        $this->withHeaders($this->workspaceHeader($otherWorkspace))
            ->getJson('/api/finance/v1/quotes/'.$quoteId)
            ->assertNotFound();

        Sanctum::actingAs($owner);
        app(WorkspaceContext::class)->set($workspace);
        $this->withHeaders($headers)
            ->deleteJson('/api/finance/v1/quotes/'.$quoteId.'/attachments/'.$attachmentId)
            ->assertOk()
            ->assertJsonPath('data.attachments', []);
    }

    public function test_quote_convert_is_idempotent_and_creates_draft_invoice(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $customer = $this->makeBuyer($workspace, 'Convert Customer');
        app(WorkspaceContext::class)->set($workspace);

        $quote = app(QuoteService::class)->create($workspace, [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'status' => 'issued',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'استشارة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ], (int) $owner->id);
        $quote = app(QuoteService::class)->accept($quote, (int) $owner->id);

        $first = $this->withHeaders($headers)->postJson('/api/finance/v1/quotes/'.$quote->id.'/convert')->assertOk();
        $this->assertNotEmpty($first->json('data.converted_invoice_id'));
        $invoiceId = (int) $first->json('data.converted_invoice_id');
        $invoice = FinanceInvoice::withoutGlobalScopes()->findOrFail($invoiceId);
        $this->assertTrue($invoice->isDraft());

        $second = $this->withHeaders($headers)->postJson('/api/finance/v1/quotes/'.$quote->id.'/convert')->assertOk();
        $this->assertSame($invoiceId, (int) $second->json('data.converted_invoice_id'));

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/quotes/'.$quote->id.'/convert')
            ->assertOk()
            ->assertJsonPath('data.converted_invoice_id', $invoiceId);

        $draftQuote = app(QuoteService::class)->create($workspace, [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'مسودة',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ], (int) $owner->id);

        $this->withHeaders($headers)
            ->postJson('/api/finance/v1/quotes/'.$draftQuote->id.'/convert')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_payments_and_receipts_accept_date_method_and_customer_filters(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $customer = $this->makeBuyer($workspace, 'Filter Buyer');

        $invoice = $this->withHeaders($headers)->postJson('/api/finance/v1/sales-invoices', array_merge(
            $this->invoiceBody($customer->id, 100),
            ['invoice_status' => 'issued']
        ))->assertCreated();

        $this->withHeaders($headers)->postJson('/api/finance/v1/sales-invoices/'.$invoice->json('data.id').'/payments', [
            'amount' => 50,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'reference' => 'REF-CASH-1',
        ])->assertOk();

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/payments?method=cash&customer_id='.$customer->id.'&from='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'REF-CASH-1');

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/payments?method=card')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/receipts?customer_id='.$customer->id.'&method=cash')
            ->assertOk();
        $this->assertNotEmpty($this->withHeaders($headers)->getJson('/api/finance/v1/receipts?customer_id='.$customer->id)->json('data'));
    }

    public function test_expense_update_persists_recurring_fields(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);

        $created = $this->withHeaders($headers)->postJson('/api/finance/v1/expenses', [
            'expense_date' => now()->toDateString(),
            'description' => 'إيجار مسودة',
            'amount' => 100,
            'tax_rate' => 15,
            'status' => 'draft',
            'is_recurring' => true,
            'recurring_frequency' => 'monthly',
            'next_due_date' => now()->addMonth()->toDateString(),
        ])->assertCreated();

        $this->assertTrue((bool) $created->json('data.is_recurring'));
        $this->assertSame('monthly', $created->json('data.recurring_frequency'));

        $this->withHeaders($headers)->putJson('/api/finance/v1/expenses/'.$created->json('data.id'), [
            'expense_date' => now()->toDateString(),
            'description' => 'إيجار محدث',
            'amount' => 120,
            'tax_rate' => 15,
            'is_recurring' => true,
            'recurring_frequency' => 'yearly',
            'next_due_date' => now()->addYear()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.recurring_frequency', 'yearly');
    }

    public function test_purchase_issue_attachments_and_payment_round_trip(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        app(WorkspaceContext::class)->set($workspace);

        $supplier = \App\Models\Finance\FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Parity Supplier',
            'status' => 'active',
        ]);

        $created = $this->withHeaders($headers)->post('/api/finance/v1/purchases', [
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'خدمة مورد',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
            'attachments' => [UploadedFile::fake()->create('po-scan.pdf', 12, 'application/pdf')],
        ])->assertCreated();

        $invoiceId = (int) $created->json('data.id');
        $this->assertSame('issued', $created->json('data.document_status'));
        $this->assertSame('po-scan.pdf', $created->json('data.attachments.0.file_name'));
        $this->assertIsArray($created->json('data.tax_breakdown'));

        $show = $this->withHeaders($headers)->getJson('/api/finance/v1/purchases/'.$invoiceId)->assertOk();
        $this->assertNotEmpty($show->json('data.journal_entries'));
        $attachmentId = (int) $show->json('data.attachments.0.id');
        $this->withHeaders($headers)
            ->get('/api/finance/v1/purchases/'.$invoiceId.'/attachments/'.$attachmentId)
            ->assertOk();

        $paid = $this->withHeaders($headers)->postJson('/api/finance/v1/purchases/'.$invoiceId.'/payments', [
            'payment_date' => now()->toDateString(),
            'amount' => 115,
            'method' => 'cash',
            'reference' => 'PUR-PAY-1',
        ])->assertOk();
        $this->assertSame('paid', $paid->json('data.invoice.payment_status'));

        $this->withHeaders($headers)
            ->getJson('/api/finance/v1/payments?invoice_id='.$invoiceId)
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'PUR-PAY-1');
    }

    public function test_invoice_line_exemption_code_is_persisted_from_client_payload(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        Sanctum::actingAs($owner);
        $headers = $this->workspaceHeader($workspace);
        $customer = $this->makeBuyer($workspace, 'Exempt Buyer');

        $created = $this->withHeaders($headers)->postJson('/api/finance/v1/sales-invoices', array_merge(
            $this->invoiceBody($customer->id, 100),
            [
                'tax_profile_type' => TaxProfileType::Exempt->value,
                'items' => [[
                    'product_name' => 'بند معفى',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'discount' => 0,
                    'tax_rate' => 0,
                    'tax_profile_type' => TaxProfileType::Exempt->value,
                    'exemption_reason' => 'صادرات',
                    'exemption_code' => 'VATEX-SA-32',
                ]],
            ]
        ))->assertCreated();

        $this->assertSame('صادرات', $created->json('data.lines.0.exemption_reason'));
        $this->assertSame('VATEX-SA-32', $created->json('data.lines.0.exemption_code'));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name = 'Finance Billing Parity'): array
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

    private function makeBuyer(Workspace $workspace, string $name): Customer
    {
        app(WorkspaceContext::class)->set($workspace);

        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@example.com',
            'vat_number' => '300111111111113',
            'address' => 'Buyer Street',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
            'district' => 'Al Balad',
            'postal_code' => '22222',
        ]);
    }

    private function completeSeller(Workspace $workspace): void
    {
        app(WorkspaceContext::class)->set($workspace);
        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update([
                'company_name' => 'Issued Co',
                'vat_number' => '310000000000003',
                'commercial_registration' => '1010000000',
                'street' => 'King Fahd Road',
                'building_number' => '1234',
                'district' => 'Al Olaya',
                'city' => 'Riyadh',
                'postal_code' => '12345',
                'country_code' => 'SA',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceBody(int $customerId, float $unitPrice = 100): array
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
                'unit_price' => $unitPrice,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function workspaceHeader(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => (string) $workspace->id];
    }
}
