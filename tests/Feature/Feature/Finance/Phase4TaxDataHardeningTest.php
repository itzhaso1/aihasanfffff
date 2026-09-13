<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxPriceMode;
use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\CustomerStatementService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\IssuedSnapshotBuilder;
use App\Services\Finance\ReportService;
use App\Services\Pos\PosOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class Phase4TaxDataHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_finance_tax_profiles_inclusive_exclusive_discount_quantity_and_invariants(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Tax Profiles Buyer');

        $standard = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'tax_price_mode' => TaxPriceMode::Exclusive->value,
            'items' => [[
                'product_name' => 'Standard',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ]);
        $this->assertSame('15.00', (string) $standard->tax_amount);
        $this->assertSame('100.00', (string) $standard->taxable_amount);
        $this->assertSame('115.00', (string) $standard->total);
        $this->assertSame(
            number_format((float) $standard->taxable_amount, 2, '.', ''),
            number_format((float) $standard->items->sum(fn ($item) => (float) $item->taxable_amount), 2, '.', '')
        );
        $this->assertSame(
            (string) $standard->tax_amount,
            number_format((float) $standard->items->sum(fn ($item) => (float) $item->tax_amount), 2, '.', '')
        );

        $zero = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'Zero',
                'quantity' => 1,
                'unit_price' => 40,
                'discount' => 0,
                'tax_type' => TaxProfileType::ZeroRated->value,
            ]],
        ]);
        $this->assertSame('0.00', (string) $zero->tax_amount);
        $this->assertSame(TaxProfileType::ZeroRated->value, $zero->tax_profile_type);

        $exempt = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_profile_type' => TaxProfileType::Exempt->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'Exempt',
                'quantity' => 1,
                'unit_price' => 30,
                'discount' => 0,
                'tax_type' => TaxProfileType::Exempt->value,
                'exemption_reason' => 'تعليمي',
            ]],
        ]);
        $this->assertSame('0.00', (string) $exempt->tax_amount);
        $this->assertSame(TaxProfileType::Exempt->value, $exempt->tax_profile_type);

        $oos = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_profile_type' => TaxProfileType::OutOfScope->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'OOS',
                'quantity' => 1,
                'unit_price' => 20,
                'discount' => 0,
                'tax_type' => TaxProfileType::OutOfScope->value,
            ]],
        ]);
        $this->assertSame('0.00', (string) $oos->tax_amount);

        $inclusive = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_price_mode' => TaxPriceMode::Inclusive->value,
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'Inclusive',
                'quantity' => 1,
                'unit_price' => 115,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ]);
        $this->assertSame(TaxPriceMode::Inclusive->value, $inclusive->tax_price_mode);
        $this->assertSame('15.00', (string) $inclusive->tax_amount);
        $this->assertSame('100.00', (string) $inclusive->taxable_amount);
        $this->assertSame('115.00', (string) $inclusive->total);

        $discounted = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'Discounted',
                'quantity' => 2,
                'unit_price' => 50,
                'discount' => 10,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ]);
        $this->assertSame('90.00', (string) $discounted->taxable_amount);
        $this->assertSame('13.50', (string) $discounted->tax_amount);
        $this->assertSame('103.50', (string) $discounted->total);

        $decimalQty = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'Hours',
                'quantity' => 1.5,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ]);
        $this->assertSame('15.00', (string) $decimalQty->taxable_amount);
        $this->assertSame('2.25', (string) $decimalQty->tax_amount);
        $this->assertSame('17.25', (string) $decimalQty->total);

        $rounding = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'Round',
                'quantity' => 1,
                'unit_price' => 10.01,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ]);
        $this->assertSame('10.01', (string) $rounding->taxable_amount);
        $this->assertSame('1.50', (string) $rounding->tax_amount);
        $this->assertSame('11.51', (string) $rounding->total);
    }

    public function test_issued_finance_tax_rate_and_snapshot_stay_stable_when_settings_change(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Stable Buyer', [
            'vat_number' => '300111111111113',
            'city' => 'Riyadh',
            'street' => 'Olaya',
        ]);
        $this->updateCompany($workspace, ['company_name' => 'Issued Co', 'vat_number' => '310000000000003']);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $issuedAt = $invoice->issued_at?->toIso8601String();
        $snapshot = $this->financeSnapshot($invoice);

        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update(['default_vat_rate' => 5, 'company_name' => 'Tomorrow Co', 'vat_number' => '320000000000003']);
        $customer->update(['name' => 'Changed Buyer', 'vat_number' => '399999999999993', 'city' => 'Jeddah']);

        $fresh = $invoice->fresh(['items']);
        $this->assertSame('15.00', (string) $fresh->tax_rate);
        $this->assertSame('15.00', (string) $fresh->tax_amount);
        $this->assertSame('115.00', (string) $fresh->total);
        $this->assertSame($issuedAt, $fresh->issued_at?->toIso8601String());
        $this->assertSame($snapshot->payload, $snapshot->fresh()->payload);
        $this->assertSame('15.00', data_get($snapshot->fresh()->payload, 'tax.amount'));
        $this->assertSame('Issued Co', data_get($snapshot->fresh()->payload, 'seller.company_name'));
        $this->assertSame('Stable Buyer', data_get($snapshot->fresh()->payload, 'buyer.name'));
        $this->assertSame('Riyadh', data_get($snapshot->fresh()->payload, 'buyer.city'));
    }

    public function test_credit_and_debit_notes_adjust_tax_totals_reports_and_statements(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Note Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $originalTax = (string) $invoice->tax_amount;
        $originalTotal = (string) $invoice->total;
        $issuedAt = $invoice->issued_at?->toIso8601String();

        $credit = app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'credit',
            'reason' => 'خصم',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'تعويض',
                'quantity' => 1,
                'unit_price' => 20,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        $debit = app(CreditNoteService::class)->create($workspace, $invoice->fresh(), [
            'type' => 'debit',
            'reason' => 'رسوم',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'رسوم',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        $invoice->refresh();
        $this->assertSame($originalTax, (string) $invoice->tax_amount);
        $this->assertSame($originalTotal, (string) $invoice->total);
        $this->assertSame($issuedAt, $invoice->issued_at?->toIso8601String());
        $this->assertSame('3.00', (string) $credit->tax_amount);
        $this->assertSame('23.00', (string) $credit->total);
        $this->assertSame('1.50', (string) $debit->tax_amount);

        $creditSnapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE)
            ->where('source_id', $credit->id)
            ->firstOrFail();
        $this->assertSame('3.00', data_get($creditSnapshot->payload, 'tax.amount'));
        $this->assertSame('23.00', data_get($creditSnapshot->payload, 'totals.total'));

        $this->expectException(RuntimeException::class);
        $credit->update(['tax_amount' => 1]);
    }

    public function test_sales_and_vat_reports_and_customer_statement_handle_credit_debit(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Report Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'credit',
            'reason' => 'خصم تقرير',
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => 'تعويض',
                'quantity' => 1,
                'unit_price' => 20,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], (int) $owner->id);

        $summary = app(ReportService::class)->summary(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        );
        $this->assertSame(12.0, $summary['vat']['output']);
        $this->assertSame(92.0, (float) $summary['salesSummary']->total_sales);
        $this->assertSame(92.0, (float) $summary['salesByCustomer']->first()->total);

        $statement = app(CustomerStatementService::class)->build(
            $workspace,
            $customer,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString()
        );
        $this->assertSame(115.0, $statement['invoices_total']);
        $this->assertSame(23.0, $statement['credits_total']);
        $this->assertSame(92.0, $statement['closing_balance']);
    }

    public function test_payment_date_is_not_used_as_finance_issue_date(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Pay Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $issuedAt = $invoice->issued_at?->toIso8601String();
        $issueDate = $invoice->issue_date?->toDateString();

        FinanceInvoicePayment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_id' => $invoice->id,
            'amount' => 115,
            'method' => 'cash',
            'payment_date' => now()->addDays(3)->toDateString(),
            'status' => 'posted',
        ]);
        app(InvoiceService::class)->syncPaymentStatus($invoice->fresh());

        $fresh = $invoice->fresh();
        $this->assertSame($issuedAt, $fresh->issued_at?->toIso8601String());
        $this->assertSame($issueDate, $fresh->issue_date?->toDateString());
        $this->assertSame('paid', $fresh->payment_status);
    }

    public function test_pos_tax_is_persisted_reconciled_and_historically_stable(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->updateCompany($workspace, ['company_name' => 'POS Seller', 'vat_number' => '310000000000003']);
        $tea = $this->menuItem($workspace, 'Tea', 80);
        $cake = $this->menuItem($workspace, 'Cake', 20);
        $order = $this->createTakeawayOrder($workspace, $owner, [
            ['pos_menu_item_id' => $tea->id, 'quantity' => 1],
            ['pos_menu_item_id' => $cake->id, 'quantity' => 1],
        ]);

        $this->assertSame(15.0, (float) $order->tax_amount);
        $this->assertSame(15.0, (float) $order->tax_rate);
        $lineTax = (float) $order->items->sum('tax_amount');
        $this->assertEqualsWithDelta(15.0, $lineTax, 0.001);

        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $this->assertSame(15.0, (float) $invoice->tax_amount);
        $this->assertSame(100.0, (float) $invoice->taxable_amount);
        $this->assertSame(15.0, (float) $invoice->tax_rate);
        $this->assertEqualsWithDelta(
            (float) $invoice->tax_amount,
            (float) $invoice->items->sum('tax_amount'),
            0.001
        );

        $snapshot = $this->posSnapshot($invoice);
        $this->assertSame('15.00', data_get($snapshot->payload, 'tax.amount'));
        $this->assertSame('15.00', data_get($snapshot->payload, 'tax.configured_rate'));
        $this->assertSame('12.00', data_get($snapshot->payload, 'lines.0.tax_amount'));
        $this->assertNotSame(null, data_get($snapshot->payload, 'lines.0.tax_rate'));
        $closedAt = $invoice->closed_at?->toIso8601String();
        $this->assertSame($closedAt, data_get($snapshot->payload, 'document.issued_at'));

        $this->setTaxRate($workspace, 25);
        $this->assertSame($snapshot->payload, $snapshot->fresh()->payload);
        app(IssuedSnapshotBuilder::class)->capturePosCashierInvoice($invoice->fresh(['items', 'orders.customer']));
        $this->assertSame('15.00', data_get($this->posSnapshot($invoice)->payload, 'tax.configured_rate'));
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_offline_pos_payload_and_replay_keep_authoritative_tax(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'شاي', 10);
        $payload = [
            'order_type' => 'takeaway',
            'client_reference' => 'pos-p4-offline',
            'offline_sale' => true,
            'currency' => 'SAR',
            'subtotal_amount' => 10,
            'discount_amount' => 0,
            'tax_amount' => 1.50,
            'total_amount' => 11.50,
            'items' => [[
                'pos_menu_item_id' => $item->id,
                'quantity' => 1,
                'unit_price' => 10,
                'name' => 'شاي مجمد',
                'tax_amount' => 1.50,
            ]],
        ];

        $first = app(PosOrderService::class)->createPosOrder($workspace, $payload, $owner);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($first, (int) $owner->id);
        $this->setTaxRate($workspace, 25);
        $replay = app(PosOrderService::class)->createPosOrder($workspace, $payload, $owner);
        $again = app(PosOrderService::class)->createInvoiceFromOrder($replay, (int) $owner->id);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame($invoice->id, $again->id);
        $this->assertSame(1.50, (float) $first->fresh()->tax_amount);
        $this->assertSame(1.50, (float) OrderItem::query()->where('order_id', $first->id)->sum('tax_amount'));
        $this->assertSame(1.50, (float) $invoice->fresh()->tax_amount);
        $this->assertSame('1.50', data_get($this->posSnapshot($invoice)->payload, 'totals.tax_amount'));
        $this->assertSame(1, PosCashierInvoice::withoutGlobalScopes()->count());
        $this->assertSame(1, IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->count());
    }

    public function test_pos_anonymous_buyer_and_issue_timestamp_remain_honest(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'Walk-in', 10);
        $placedAt = now()->subDay();
        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'offline_sale' => true,
            'placed_at' => $placedAt->toIso8601String(),
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);

        $this->assertSame('anonymous', data_get($snapshot->payload, 'buyer.kind'));
        $this->assertTrue((bool) data_get($snapshot->payload, 'buyer.walk_in'));
        $this->assertSame($invoice->closed_at?->toIso8601String(), data_get($snapshot->payload, 'document.issued_at'));
        $this->assertSame($placedAt->toDateString(), $order->fresh()->placed_at?->toDateString());
        $this->assertNotSame($order->fresh()->placed_at?->toDateString(), $invoice->closed_at?->toDateString());
    }

    public function test_cross_workspace_pos_snapshot_is_still_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspaceA, 'Tea', 100);
        $order = $this->createTakeawayOrder($workspaceA, $ownerA, [
            ['pos_menu_item_id' => $item->id, 'quantity' => 1],
        ]);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $ownerA->id);
        $this->createWorkspaceOwner();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cross-workspace snapshot creation is not allowed.');
        app(IssuedSnapshotBuilder::class)->capturePosCashierInvoice(
            PosCashierInvoice::withoutGlobalScopes()->with(['items', 'orders.customer'])->findOrFail($invoice->id)
        );
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Phase4 Workspace',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);

        foreach (['finance', 'pos', 'products', 'orders', 'customers'] as $feature) {
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

        app(WorkspaceContext::class)->set($workspace);
        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        $this->setTaxRate($workspace, 15);

        return [$user, $workspace->fresh()];
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
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateCompany(Workspace $workspace, array $attributes): void
    {
        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update($attributes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issueFinance(Workspace $workspace, Customer $customer, int $actorId, array $overrides = []): FinanceInvoice
    {
        $payload = array_merge([
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => TaxProfileType::Standard->value,
            'tax_rate' => 15,
            'tax_price_mode' => TaxPriceMode::Exclusive->value,
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
            ]],
        ], $overrides);

        return app(InvoiceService::class)->create($workspace, $payload, $actorId);
    }

    private function menuItem(Workspace $workspace, string $name, float $price): PosMenuItem
    {
        return PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'item_type' => 'خدمات',
            'price' => $price,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createTakeawayOrder(Workspace $workspace, User $owner, array $items): Order
    {
        return app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'items' => $items,
        ], $owner);
    }

    private function financeSnapshot(FinanceInvoice $invoice): IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
    }

    private function posSnapshot(PosCashierInvoice $invoice): IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
    }

    private function setTaxRate(Workspace $workspace, float $rate): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $pos = is_array($settings['pos'] ?? null) ? $settings['pos'] : [];
        $pos['tax_rate'] = $rate;
        $settings['pos'] = $pos;
        $workspace->update(['settings' => $settings]);
    }
}
