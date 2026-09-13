<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\BillingScheduleService;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\Tax\TaxCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class TaxEngineHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_standard_fifteen_percent_exclusive_invoice(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'VAT Customer');
        $invoice = $this->storeInvoice($user, $workspace, $customer);

        $this->assertSame('exclusive', $invoice->tax_price_mode);
        $this->assertSame('100.00', (string) $invoice->subtotal);
        $this->assertSame('100.00', (string) $invoice->taxable_amount);
        $this->assertSame('15.00', (string) $invoice->tax_amount);
        $this->assertSame('115.00', (string) $invoice->total);
        $this->assertSame('15.00', (string) $invoice->items->first()?->tax_amount);
        $this->assertTrue($this->totalsReconcile($invoice));
        $this->assertNotEmpty($invoice->tax_breakdown);
        $this->assertSame('standard', $invoice->tax_breakdown[0]['tax_profile_type']);
    }

    public function test_custom_workspace_rate_applies_only_when_line_rate_omitted(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Custom Rate Customer');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.settings.index'))
            ->assertOk();

        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['default_vat_rate' => 10]);
        FinanceTaxRate::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('is_default', true)->update(['rate' => 10]);

        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'issued',
            'items' => [[
                'product_name' => 'خدمة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_type' => 'standard',
            ]],
        ], (int) $user->id);

        $this->assertSame('10.00', (string) $invoice->tax_amount);
        $this->assertSame('110.00', (string) $invoice->total);
    }

    public function test_zero_rated_exempt_and_out_of_scope_do_not_charge_vat(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Class Customer');

        foreach (['zero_rated', 'exempt', 'out_of_scope'] as $type) {
            $before = FinanceInvoice::withoutGlobalScopes()->max('id') ?? 0;
            $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
                ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                    'tax_type' => $type,
                    'tax_rate' => 0,
                ]))
                ->assertRedirect();
            $invoice = FinanceInvoice::withoutGlobalScopes()->where('id', '>', $before)->orderByDesc('id')->firstOrFail();
            $this->assertSame($type, $invoice->items->first()?->tax_profile_type);
            $this->assertSame('0.00', (string) $invoice->tax_amount);
            $this->assertSame('0.00', (string) $invoice->items->first()?->tax_rate);
            $this->assertSame('100.00', (string) $invoice->total);
        }
    }

    public function test_mixed_tax_invoice_keeps_line_classifications_and_category_totals(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Mixed Customer');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([
                    ['product_name' => 'A', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax_rate' => 15, 'tax_type' => 'standard'],
                    ['product_name' => 'B', 'quantity' => 1, 'unit_price' => 40, 'discount' => 0, 'tax_rate' => 0, 'tax_type' => 'zero_rated'],
                    ['product_name' => 'C', 'quantity' => 1, 'unit_price' => 25, 'discount' => 5, 'tax_rate' => 0, 'tax_type' => 'exempt', 'exemption_reason' => 'سلعة معفاة داخلياً'],
                    ['product_name' => 'D', 'quantity' => 2, 'unit_price' => 10, 'discount' => 0, 'tax_rate' => 0, 'tax_type' => 'out_of_scope'],
                ]),
            ])->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->with('items')->firstOrFail();
        $types = $invoice->items->pluck('tax_profile_type')->sort()->values()->all();
        $this->assertSame(['exempt', 'out_of_scope', 'standard', 'zero_rated'], $types);
        $this->assertSame('180.00', (string) $invoice->taxable_amount);
        $this->assertSame('15.00', (string) $invoice->tax_amount);
        $this->assertSame('195.00', (string) $invoice->total);
        $this->assertSame('سلعة معفاة داخلياً', $invoice->items->firstWhere('tax_profile_type', 'exempt')?->exemption_reason);
        $this->assertCount(4, $invoice->tax_breakdown);
        $this->assertTrue($this->totalsReconcile($invoice));
    }

    public function test_decimal_quantity_price_discount_and_rounding(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Decimal Customer');
        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'issued',
            'items' => [
                ['product_name' => 'Qty', 'quantity' => 2.5, 'unit_price' => 10.20, 'discount' => 0, 'tax_type' => 'standard', 'tax_rate' => 15],
                ['product_name' => 'Penny', 'quantity' => 1, 'unit_price' => 0.01, 'discount' => 0, 'tax_type' => 'standard', 'tax_rate' => 15],
                ['product_name' => 'Disc', 'quantity' => 1, 'unit_price' => 100, 'discount' => 10, 'tax_type' => 'standard', 'tax_rate' => 15],
            ],
        ], (int) $user->id);

        $this->assertSame('25.50', (string) $invoice->items[0]->taxable_amount);
        $this->assertSame('3.83', (string) $invoice->items[0]->tax_amount);
        $this->assertSame('0.01', (string) $invoice->items[1]->taxable_amount);
        $this->assertSame('0.00', (string) $invoice->items[1]->tax_amount);
        $this->assertSame('90.00', (string) $invoice->items[2]->taxable_amount);
        $this->assertSame('13.50', (string) $invoice->items[2]->tax_amount);
        $this->assertTrue($this->totalsReconcile($invoice));
    }

    public function test_inclusive_pricing_is_opt_in_and_does_not_change_default_path(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Inclusive Customer');

        $exclusive = $this->storeInvoice($user, $workspace, $customer);
        $this->assertSame('exclusive', $exclusive->tax_price_mode);
        $this->assertSame('115.00', (string) $exclusive->total);

        $inclusive = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'issued',
            'tax_price_mode' => 'inclusive',
            'items' => [[
                'product_name' => 'شامل',
                'quantity' => 1,
                'unit_price' => 115,
                'discount' => 0,
                'tax_type' => 'standard',
                'tax_rate' => 15,
            ]],
        ], (int) $user->id);

        $this->assertSame('inclusive', $inclusive->tax_price_mode);
        $this->assertSame('100.00', (string) $inclusive->taxable_amount);
        $this->assertSame('15.00', (string) $inclusive->tax_amount);
        $this->assertSame('115.00', (string) $inclusive->total);
    }

    public function test_issued_invoice_tax_does_not_change_when_workspace_rate_changes(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'History Customer');
        $first = $this->storeInvoice($user, $workspace, $customer);
        $fingerprint = [
            (string) $first->tax_amount,
            (string) $first->total,
            (string) $first->items->first()?->tax_rate,
        ];

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.settings.index'));
        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['default_vat_rate' => 5]);
        FinanceTaxRate::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('is_default', true)->update(['rate' => 5]);

        $first->refresh()->load('items');
        $this->assertSame($fingerprint, [
            (string) $first->tax_amount,
            (string) $first->total,
            (string) $first->items->first()?->tax_rate,
        ]);

        $second = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'issued',
            'items' => [[
                'product_name' => 'بعد التغيير',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_type' => 'standard',
            ]],
        ], (int) $user->id);

        $this->assertSame('5.00', (string) $second->tax_amount);
        $this->assertSame('15.00', (string) $first->fresh()->tax_amount);
    }

    public function test_credit_and_debit_notes_use_the_same_engine(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Note Engine Customer');
        $invoice = $this->storeInvoice($user, $workspace, $customer);

        $credit = app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'credit',
            'reason' => 'خصم',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'خصم',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ], (int) $user->id);

        $this->assertSame('1.50', (string) $credit->tax_amount);
        $this->assertSame('11.50', (string) $credit->total);
        $this->assertSame('standard', $credit->items->first()?->tax_profile_type);
        $this->assertNotEmpty($credit->tax_breakdown);

        $debit = app(CreditNoteService::class)->create($workspace, $invoice->fresh(), [
            'type' => 'debit',
            'reason' => 'رسوم',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'رسوم',
                'quantity' => 1,
                'unit_price' => 20,
                'discount' => 0,
                'tax_rate' => 0,
                'tax_type' => 'exempt',
            ]],
        ], (int) $user->id);

        $this->assertSame('0.00', (string) $debit->tax_amount);
        $this->assertSame('exempt', $debit->items->first()?->tax_profile_type);
        $invoice->refresh()->load('items');
        $this->assertSame('15.00', (string) $invoice->tax_amount);
    }

    public function test_billing_schedule_uses_workspace_profile_and_invoice_engine(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Schedule Tax Customer');
        $service = app(BillingScheduleService::class);
        $schedule = $service->create($workspace, [
            'customer_id' => $customer->id,
            'title' => 'اشتراك',
            'frequency' => 'monthly',
            'amount' => 100,
            'start_date' => now()->toDateString(),
            'status' => 'active',
        ], (int) $user->id);

        $this->assertSame(15.0, (float) $schedule->item_snapshot[0]['tax_rate']);
        $invoice = $service->generateOne($schedule, now());
        $this->assertNotNull($invoice);
        $this->assertSame('15.00', (string) $invoice->tax_amount);
        $this->assertSame('115.00', (string) $invoice->total);
        $this->assertTrue($this->totalsReconcile($invoice));
    }

    public function test_purchase_invoice_still_calculates_tax_with_zatca_not_required(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $supplier = FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'مورد ضريبي',
            'status' => 'active',
            'vat_number' => '300000000000003',
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'purchase',
                'supplier_id' => $supplier->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'zatca_requirement' => 'required',
                'items_json' => json_encode([[
                    'product_name' => 'شراء',
                    'quantity' => 1,
                    'unit_price' => 200,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]]),
            ])->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('purchase', $invoice->type);
        $this->assertSame('not_required', $invoice->zatca_requirement);
        $this->assertSame('30.00', (string) $invoice->tax_amount);
        $this->assertSame('230.00', (string) $invoice->total);
    }

    public function test_invalid_tax_input_is_rejected_server_side(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Invalid Customer');
        $service = app(InvoiceService::class);

        $this->expectException(RuntimeException::class);
        $service->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'draft',
            'items' => [[
                'product_name' => 'سيئ',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_type' => 'standard',
                'tax_rate' => -5,
            ]],
        ], (int) $user->id);
    }

    public function test_issue_fails_when_persisted_totals_do_not_reconcile(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'Reconcile Customer');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id, [
                'invoice_status' => 'draft',
            ]))->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->firstOrFail();
        DB::table('finance_invoices')->where('id', $invoice->id)->update([
            'tax_amount' => 99,
            'total' => 199,
        ]);

        $this->expectException(RuntimeException::class);
        app(InvoiceService::class)->issue($invoice->fresh(), (int) $user->id);
    }

    public function test_workspace_isolation_for_default_rate_resolution(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$userB, $workspaceB] = $this->createWorkspaceOwner('company');
        $customerA = $this->makeCustomer($workspaceA, 'A Customer');
        $customerB = $this->makeCustomer($workspaceB, 'B Customer');

        foreach ([[$userA, $workspaceA, 15], [$userB, $workspaceB, 10]] as [$user, $workspace, $rate]) {
            $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
                ->get(route('workspace.finance.settings.index'));
            FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['default_vat_rate' => $rate]);
            FinanceTaxRate::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('is_default', true)->update(['rate' => $rate]);
        }

        $invoiceA = app(InvoiceService::class)->create($workspaceA, [
            'type' => 'sales',
            'customer_id' => $customerA->id,
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'issued',
            'items' => [['product_name' => 'A', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax_type' => 'standard']],
        ], (int) $userA->id);
        $invoiceB = app(InvoiceService::class)->create($workspaceB, [
            'type' => 'sales',
            'customer_id' => $customerB->id,
            'issue_date' => now()->toDateString(),
            'invoice_status' => 'issued',
            'items' => [['product_name' => 'B', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0, 'tax_type' => 'standard']],
        ], (int) $userB->id);

        $this->assertSame('15.00', (string) $invoiceA->tax_amount);
        $this->assertSame('10.00', (string) $invoiceB->tax_amount);
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->where('workspace_id', $workspaceA->id)->whereKey($invoiceB->id)->count());
    }

    public function test_pdf_uses_persisted_historical_tax_values(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $customer = $this->makeCustomer($workspace, 'PDF Tax Customer');
        $invoice = $this->storeInvoice($user, $workspace, $customer);

        FinanceSetting::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update(['default_vat_rate' => 1]);

        $html = view('workspace.finance.invoices.pdf', [
            'invoice' => $invoice->load(['items', 'customer', 'supplier']),
            'setting' => null,
            'companySnapshot' => $invoice->company_snapshot,
            'recipientSnapshot' => $invoice->recipient_snapshot,
            'pdfSnapshot' => $invoice->pdf_snapshot,
            'snapshotsAuthoritative' => true,
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('15.00', $html);
        $this->assertStringContainsString('115.00', $html);
        $this->assertStringContainsString('قياسية', $html);
        $this->assertStringContainsString('الفوترة الإلكترونية ZATCA: غير مهيأة', $html);
        $this->assertStringNotContainsString('ZATCA compliant', $html);
        $this->assertStringNotContainsString('Fatoora ready', $html);
    }

    public function test_create_form_exposes_classifications_without_zatca_claims(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner('company');
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.create'))
            ->assertOk()
            ->assertSee('قياسية')
            ->assertSee('صفرية')
            ->assertSee('معفاة')
            ->assertSee('خارج النطاق')
            ->assertSee('غير شامل الضريبة')
            ->assertDontSee('ZATCA compliant')
            ->assertDontSee('Fatoora ready');
    }

    public function test_fallback_constant_is_not_copied_into_billing_schedule_service(): void
    {
        $source = file_get_contents(app_path('Services/Finance/BillingScheduleService.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('FALLBACK_STANDARD_RATE', $source);
        $this->assertSame(15.00, TaxCalculationService::FALLBACK_STANDARD_RATE);
    }

    private function totalsReconcile(FinanceInvoice $invoice): bool
    {
        $invoice->loadMissing('items');
        $lineTax = $invoice->items->sum(fn (FinanceInvoiceItem $item): float => (float) $item->tax_amount);
        $lineTaxable = $invoice->items->sum(fn (FinanceInvoiceItem $item): float => (float) $item->taxable_amount);
        $lineTotal = $invoice->items->sum(fn (FinanceInvoiceItem $item): float => (float) $item->total);

        return abs($lineTax - (float) $invoice->tax_amount) < 0.001
            && abs($lineTaxable - (float) $invoice->taxable_amount) < 0.001
            && abs($lineTotal - (float) $invoice->total) < 0.001
            && abs(((float) $invoice->taxable_amount + (float) $invoice->tax_amount) - (float) $invoice->total) < 0.001;
    }

    private function storeInvoice(User $user, Workspace $workspace, Customer $customer): FinanceInvoice
    {
        $before = FinanceInvoice::withoutGlobalScopes()->max('id') ?? 0;
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), $this->invoicePayload($customer->id))
            ->assertRedirect();

        return FinanceInvoice::withoutGlobalScopes()
            ->with('items')
            ->where('workspace_id', $workspace->id)
            ->where('id', '>', $before)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(int $customerId, array $overrides = []): array
    {
        return [
            'type' => 'sales',
            'customer_id' => $customerId,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => $overrides['invoice_status'] ?? 'issued',
            'tax_profile_type' => $overrides['tax_profile_type'] ?? 'standard',
            'tax_rate' => $overrides['tax_rate'] ?? 15,
            'items_json' => json_encode([[
                'product_name' => 'خدمة فوترة',
                'description' => 'بند اختبار',
                'quantity' => 1,
                'unit_price' => $overrides['unit_price'] ?? 100,
                'discount' => $overrides['discount'] ?? 0,
                'tax_rate' => $overrides['tax_rate'] ?? 15,
                'tax_type' => $overrides['tax_type'] ?? 'standard',
                'exemption_reason' => $overrides['exemption_reason'] ?? null,
            ]]),
        ];
    }

    private function makeCustomer(Workspace $workspace, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ]);
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
            ?? Plan::query()->where('workspace_type', $workspaceType)->where('is_active', true)->orderByDesc('price')->first();
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
