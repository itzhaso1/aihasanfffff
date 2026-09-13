<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceDocumentDelivery;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceReceipt;
use App\Models\Finance\FinanceSupplier;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Email\CentralEmailService;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PriceListService;
use App\Services\Finance\QuoteService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceWebCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
        $this->seed(FoundationSeeder::class);
    }

    public function test_sales_payment_creates_workspace_safe_receipt_tied_to_payment(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.store', $invoice), [
                'amount' => 50,
                'method' => 'cash',
                'payment_date' => now()->toDateString(),
                'reference' => 'REF-1',
                'notes' => 'دفعة جزئية',
            ])
            ->assertRedirect(route('workspace.finance.invoices.show', $invoice));

        $payment = FinanceInvoicePayment::withoutGlobalScopes()->firstOrFail();
        $receipt = FinanceReceipt::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($payment->id, $receipt->payment_id);
        $this->assertSame($invoice->id, $receipt->invoice_id);
        $this->assertSame($workspace->id, $receipt->workspace_id);
        $this->assertSame((string) $payment->amount, (string) $receipt->amount);
        $this->assertMatchesRegularExpression('/^RCT-\d{4}-\d{4}$/', $receipt->receipt_number);
        $this->assertSame('posted', $receipt->status);
        $this->assertSame(50.0, (float) $invoice->fresh()->amount_paid);
        $this->assertTrue(AuditLog::query()->where('action', 'receipt_created')->where('workspace_id', $workspace->id)->exists());
    }

    public function test_duplicate_payment_reference_does_not_duplicate_payment_or_receipt(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $payload = [
            'amount' => 40,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'reference' => 'DUP-REF',
        ];

        app(InvoicePaymentService::class)->recordPayment($invoice, $payload, (int) $user->id);
        app(InvoicePaymentService::class)->recordPayment($invoice->fresh(), $payload, (int) $user->id);

        $this->assertSame(1, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(1, FinanceReceipt::withoutGlobalScopes()->count());
    }

    public function test_payment_cannot_exceed_remaining_amount(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.invoices.show', $invoice))
            ->post(route('workspace.finance.invoices.payments.store', $invoice), [
                'amount' => (float) $invoice->amount_due + 10,
                'method' => 'cash',
                'payment_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceReceipt::withoutGlobalScopes()->count());
    }

    public function test_purchase_payment_does_not_create_customer_receipt(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $supplier = FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'مورد اختبار',
        ]);
        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'purchase',
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'شراء',
                'quantity' => 1,
                'unit_price' => 80,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ], (int) $user->id);

        app(InvoicePaymentService::class)->recordPayment($invoice, [
            'amount' => 20,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ], (int) $user->id);

        $this->assertSame(1, FinanceInvoicePayment::withoutGlobalScopes()->count());
        $this->assertSame(0, FinanceReceipt::withoutGlobalScopes()->count());
    }

    public function test_reverse_payment_voids_receipt_and_requires_reverse_permission(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();
        $payment = app(InvoicePaymentService::class)->recordPayment($invoice, [
            'amount' => 30,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'reference' => 'REV-1',
        ], (int) $user->id);
        $receipt = FinanceReceipt::withoutGlobalScopes()->where('payment_id', $payment->id)->firstOrFail();

        $agent = $this->attachStaff($workspace, ['invoices.view', 'invoices.cancel']);
        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.reverse', [$invoice, $payment]), [
                'reversal_reason' => 'محاولة',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.payments.reverse', [$invoice, $payment]), [
                'reversal_reason' => 'عكس اختباري',
            ])
            ->assertRedirect();

        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame('voided', $receipt->fresh()->status);
        $this->assertSame(0.0, (float) $invoice->fresh()->amount_paid);
    }

    public function test_receipt_pdf_and_email_use_payment_amount(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedSalesInvoice(true);
        $payment = app(InvoicePaymentService::class)->recordPayment($invoice, [
            'amount' => 25,
            'method' => 'bank_transfer',
            'payment_date' => now()->toDateString(),
            'reference' => 'TRX-25',
        ], (int) $user->id);
        $receipt = FinanceReceipt::withoutGlobalScopes()->where('payment_id', $payment->id)->firstOrFail();

        $pdf = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.receipts.pdf', $receipt));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));

        $payloads = [];
        $this->fakeSuccessfulMailer($payloads, 'receipt_email');
        $this->forbidWhatsApp();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.receipts.send', $receipt), [
                'email' => $customer->email,
                'attach_pdf' => '1',
            ])
            ->assertRedirect(route('workspace.finance.receipts.show', $receipt));

        $this->assertCount(1, $payloads);
        $this->assertSame('receipt_email', $payloads[0]['template']);
        $this->assertSame(1, FinanceDocumentDelivery::withoutGlobalScopes()->where('document_type', 'receipt')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'receipt_sent')->exists());
    }

    public function test_receipt_isolated_between_workspaces(): void
    {
        [$userA, $workspaceA, $invoiceA] = $this->issuedSalesInvoice();
        app(InvoicePaymentService::class)->recordPayment($invoiceA, [
            'amount' => 15,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ], (int) $userA->id);
        $receiptA = FinanceReceipt::withoutGlobalScopes()->firstOrFail();

        [$userB, $workspaceB] = $this->createWorkspaceOwner('company');

        $this->actingAs($userB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->get(route('workspace.finance.receipts.show', $receiptA))
            ->assertNotFound();
    }

    public function test_manual_reminder_emails_outstanding_invoice_only(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedSalesInvoice(true);
        $payloads = [];
        $this->fakeSuccessfulMailer($payloads, 'invoice_reminder_email');
        $this->forbidWhatsApp();
        $stageBefore = $invoice->reminder_stage;
        $financialBefore = [
            'amount_due' => (string) $invoice->amount_due,
            'amount_paid' => (string) $invoice->amount_paid,
            'invoice_status' => $invoice->resolvedInvoiceStatus(),
        ];

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.remind', $invoice), [
                'email' => $customer->email,
                'subject' => 'تذكير',
                'message' => 'يرجى السداد',
                'attach_pdf' => '1',
            ])
            ->assertRedirect(route('workspace.finance.invoices.show', $invoice));

        $fresh = $invoice->fresh();
        $this->assertSame($stageBefore, $fresh->reminder_stage);
        $this->assertSame($financialBefore['amount_due'], (string) $fresh->amount_due);
        $this->assertSame($financialBefore['amount_paid'], (string) $fresh->amount_paid);
        $this->assertSame($financialBefore['invoice_status'], $fresh->resolvedInvoiceStatus());
        $this->assertNotNull($fresh->last_reminder_sent_at);
        $this->assertSame('invoice_reminder_email', $payloads[0]['template']);
        $this->assertSame('invoice_reminder', FinanceDocumentDelivery::withoutGlobalScopes()->value('document_type'));
        $this->assertTrue(AuditLog::query()->where('action', 'invoice_reminder_sent')->exists());

        $paid = app(InvoicePaymentService::class)->recordPayment($fresh, [
            'amount' => (float) $fresh->amount_due,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'reference' => 'FULL',
        ], (int) $user->id);
        $this->assertNotNull($paid);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.invoices.show', $fresh))
            ->post(route('workspace.finance.invoices.remind', $fresh->fresh()), [
                'email' => $customer->email,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_reminder_forbidden_without_permission_and_draft_rejected(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedSalesInvoice(true);
        $agent = $this->attachStaff($workspace, ['invoices.view', 'invoices.send']);

        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.remind', $invoice), [
                'email' => $customer->email,
            ])
            ->assertForbidden();

        $draft = app(InvoiceService::class)->create($workspace, $this->invoicePayload($customer->id), (int) $user->id);
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.finance.invoices.show', $draft))
            ->post(route('workspace.finance.invoices.remind', $draft), [
                'email' => $customer->email,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_csv_exports_are_workspace_scoped_and_permission_gated(): void
    {
        [$userA, $workspaceA] = $this->issuedSalesInvoice(false, 'Alpha Isolation Co');
        [$userB, $workspaceB] = $this->issuedSalesInvoice(false, 'Beta Isolation Co');

        $csv = $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.exports.download', 'invoices'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Alpha Isolation Co', $csv);
        $this->assertStringNotContainsString('Beta Isolation Co', $csv);

        $agent = $this->attachStaff($workspaceA, ['invoices.view']);
        $this->actingAs($agent)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.finance.exports.download', 'quotes'))
            ->assertForbidden();
        $this->assertNotNull($userB);
        $this->assertNotNull($workspaceB);
    }

    public function test_statement_csv_and_credit_note_pdf(): void
    {
        [$user, $workspace, $invoice, $customer] = $this->issuedSalesInvoice(true);
        app(InvoicePaymentService::class)->recordPayment($invoice, [
            'amount' => 10,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ], (int) $user->id);

        $note = app(CreditNoteService::class)->create($workspace, $invoice->fresh(), [
            'type' => 'credit',
            'reason' => 'تسوية اختبار',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'تسوية',
                'quantity' => 1,
                'unit_price' => 5,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $user->id);

        $csv = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.statements.show', [
                'customer_id' => $customer->id,
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
                'csv' => 1,
            ]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($invoice->invoice_number, $csv);

        $pdf = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.credit-notes.pdf', [$invoice, $note]));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertInstanceOf(FinanceCreditNote::class, $note);
    }

    public function test_payment_link_boundary_does_not_create_order_or_mark_paid(): void
    {
        [$user, $workspace, $invoice] = $this->issuedSalesInvoice();

        $html = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('الدفع الإلكتروني', $html);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $this->assertGreaterThan(0, (float) $invoice->fresh()->amount_due);
    }

    public function test_approved_price_list_wins_over_product_price(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $product = Product::factory()->create([
            'workspace_id' => $workspace->id,
            'price' => 80,
        ]);

        $list = app(PriceListService::class)->create($workspace, [
            'name' => 'قائمة مالية',
            'currency' => 'SAR',
            'effective_from' => now()->toDateString(),
        ], (int) $user->id);
        app(PriceListService::class)->addItem($list, [
            'product_id' => $product->id,
            'price' => 120,
            'tax_rate' => 15,
            'is_active' => true,
        ]);
        app(PriceListService::class)->approve($list, (int) $user->id);

        $catalog = app(PriceListService::class)->effectivePricesByProductId((int) $workspace->id);
        $this->assertSame(120.0, $catalog[$product->id]['price']);
        $this->assertSame(15.0, $catalog[$product->id]['tax_rate']);
    }

    public function test_quote_conversion_still_creates_draft_invoice(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $customer = $this->makeCustomer($workspace, 'Convert Customer');
        $quote = app(QuoteService::class)->create($workspace, [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'بند',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ], (int) $user->id);
        app(QuoteService::class)->issue($quote, (int) $user->id);
        app(QuoteService::class)->accept($quote->fresh(), (int) $user->id);
        $converted = app(QuoteService::class)->convert($quote->fresh(), (int) $user->id);
        $invoice = FinanceInvoice::withoutGlobalScopes()->findOrFail($converted->converted_invoice_id);
        $this->assertSame('draft', $invoice->resolvedInvoiceStatus());
    }

    /**
     * @return array{0: User, 1: Workspace, 2: FinanceInvoice, 3?: Customer}
     */
    private function issuedSalesInvoice(bool $withCustomer = false, string $customerName = 'Billing Customer'): array
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $customer = $this->makeCustomer($workspace, $customerName);
        $payload = $this->invoicePayload($customer->id);
        $payload['invoice_status'] = 'issued';
        $invoice = app(InvoiceService::class)->create($workspace, $payload, (int) $user->id);

        return $withCustomer
            ? [$user, $workspace, $invoice, $customer]
            : [$user, $workspace, $invoice];
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
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function fakeSuccessfulMailer(array &$payloads, string $defaultTemplate): void
    {
        $mailer = Mockery::mock(CentralEmailService::class);
        $mailer->shouldReceive('send')->andReturnUsing(function (array $payload) use (&$payloads, $defaultTemplate) {
            $payloads[] = $payload;
            $to = $payload['to'] ?? [];
            $recipient = is_array($to) ? implode(', ', $to) : (string) $to;

            return EmailLog::query()->create([
                'workspace_id' => $payload['workspace_id'] ?? null,
                'template' => $payload['template'] ?? $defaultTemplate,
                'recipient' => $recipient,
                'subject' => $payload['subject'] ?? null,
                'status' => 'sent',
                'provider' => 'test',
                'provider_message_id' => 'msg-'.uniqid(),
                'sent_at' => now(),
                'meta' => $payload['meta'] ?? null,
            ]);
        });
        $this->app->instance(CentralEmailService::class, $mailer);
    }

    private function forbidWhatsApp(): void
    {
        $whatsapp = Mockery::mock(WhatsAppOutboundService::class);
        $whatsapp->shouldNotReceive('sendText');
        $this->app->instance(WhatsAppOutboundService::class, $whatsapp);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCustomer(Workspace $workspace, string $name, array $attributes = []): Customer
    {
        return Customer::withoutGlobalScopes()->create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@example.com',
        ], $attributes));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function attachStaff(Workspace $workspace, array $permissions): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'agent',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        foreach (['finance', 'products', 'orders', 'customers'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }
        $plan = Plan::query()
            ->where('workspace_type', $workspaceType)
            ->where('code', $workspaceType.'_pro')
            ->first()
            ?? Plan::query()
                ->where('workspace_type', $workspaceType)
                ->where('is_active', true)
                ->orderByDesc('price')
                ->first();
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

        return [$user, $workspace];
    }
}
