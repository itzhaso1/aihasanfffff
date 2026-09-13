<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\ComplianceStateMachine;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\ElectronicTaxClassification;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\EInvoiceFactory;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Pos\PosOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class Phase5EInvoiceDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_finance_invoice_snapshot_maps_to_einvoice_document(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'E-Invoice Buyer', [
            'vat_number' => '300111111111113',
            'address' => 'Riyadh',
        ]);
        $this->updateCompany($workspace, [
            'company_name' => 'Issued Co',
            'vat_number' => '310000000000003',
            'city' => 'Jeddah',
        ]);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $snapshot = $this->financeSnapshot($invoice);
        $document = app(EInvoiceFactory::class)->make($snapshot);

        $this->assertTrue($document->snapshotIdentityMatches($snapshot));
        $this->assertSame(ElectronicDocumentKind::TaxInvoice, $document->kind());
        $this->assertSame(InvoiceTypeCode::TaxInvoice, $document->typeCode());
        $this->assertSame(InvoiceTransactionCode::Standard, $document->transactionCode());
        $this->assertSame($invoice->invoice_number, $document->documentNumber);
        $this->assertSame('SAR', $document->currency);
        $this->assertSame('Issued Co', $document->seller->name);
        $this->assertSame('310000000000003', $document->seller->vatNumber);
        $this->assertSame('E-Invoice Buyer', $document->buyer->name);
        $this->assertSame('300111111111113', $document->buyer->vatNumber);
        $this->assertSame('15.00', $document->tax->amount);
        $this->assertSame('15.00', $document->tax->rate);
        $this->assertSame(ElectronicTaxClassification::Standard, $document->tax->classification);
        $this->assertSame('100.00', $document->totals->subtotal);
        $this->assertSame('115.00', $document->totals->total);
        $this->assertSame('issued', $document->businessStatus);
        $this->assertSame(ComplianceStatus::Ready, $document->complianceStatus);
        $this->assertNotSame($document->businessStatus, $document->complianceStatus->value);
        $this->assertNotSame($document->paymentStatus, $document->complianceStatus->value);
    }

    public function test_finance_credit_and_debit_note_snapshots_map_to_einvoice_documents(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Note Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $credit = app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => 'credit',
            'reason' => 'خصم تجاري',
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
            'reason' => 'رسوم إضافية',
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

        $creditDocument = app(EInvoiceFactory::class)->make($this->noteSnapshot($credit));
        $debitDocument = app(EInvoiceFactory::class)->make($this->noteSnapshot($debit));

        $this->assertSame(ElectronicDocumentKind::CreditNote, $creditDocument->kind());
        $this->assertSame(InvoiceTypeCode::CreditNote, $creditDocument->typeCode());
        $this->assertSame(InvoiceTransactionCode::Standard, $creditDocument->transactionCode());
        $this->assertSame($credit->note_number, $creditDocument->documentNumber);
        $this->assertSame((int) $invoice->id, $creditDocument->originalDocument?->invoiceId);
        $this->assertSame($invoice->invoice_number, $creditDocument->originalDocument?->invoiceNumber);
        $this->assertSame('خصم تجاري', $creditDocument->reason);
        $this->assertSame('3.00', $creditDocument->tax->amount);
        $this->assertSame('23.00', $creditDocument->totals->total);

        $this->assertSame(ElectronicDocumentKind::DebitNote, $debitDocument->kind());
        $this->assertSame(InvoiceTypeCode::DebitNote, $debitDocument->typeCode());
        $this->assertSame(InvoiceTransactionCode::Standard, $debitDocument->transactionCode());
        $this->assertSame($invoice->invoice_number, $debitDocument->originalDocument?->invoiceNumber);
        $this->assertSame('رسوم إضافية', $debitDocument->reason);
        $this->assertSame('1.50', $debitDocument->tax->amount);

        $freshInvoice = $invoice->fresh();
        $this->assertSame('115.00', (string) $freshInvoice->total);
        $this->assertSame($invoice->invoice_number, $freshInvoice->invoice_number);
    }

    public function test_pos_cashier_invoice_snapshot_maps_to_einvoice_document(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->updateCompany($workspace, [
            'company_name' => 'POS Seller Co',
            'vat_number' => '310000000000003',
        ]);
        $customer = $this->makeCustomer($workspace, 'POS Buyer', ['vat_number' => '300111111111113']);
        $tea = $this->menuItem($workspace, 'Service A', 100);
        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'customer_id' => $customer->id,
            'items' => [['pos_menu_item_id' => $tea->id, 'quantity' => 1]],
        ], $owner);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);
        $document = app(EInvoiceFactory::class)->make($snapshot);

        $this->assertSame(IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE, $document->sourceType);
        $this->assertSame(ElectronicDocumentKind::PosCashierInvoice, $document->kind());
        $this->assertNull($document->typeCode());
        $this->assertNull($document->transactionCode());
        $this->assertSame($invoice->invoice_number, $document->documentNumber);
        $this->assertSame('POS Seller Co', $document->seller->name);
        $this->assertSame('POS Buyer', $document->buyer->name);
        $this->assertFalse($document->buyer->walkIn);
        $this->assertSame('15.00', $document->tax->amount);
        $this->assertSame('15.00', $document->tax->rate);
        $this->assertSame('115.00', $document->totals->total);
        $this->assertSame(0, FinanceInvoice::withoutGlobalScopes()->count());
    }

    public function test_type_catalog_maps_standard_and_simplified_finance_invoices(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Catalog Buyer');

        $standard = app(EInvoiceFactory::class)->make(
            $this->financeSnapshot($this->issueFinance($workspace, $customer, (int) $owner->id))
        );
        $simplified = app(EInvoiceFactory::class)->make(
            $this->financeSnapshot($this->issueFinance($workspace, $customer, (int) $owner->id, [
                'tax_document_subtype' => 'simplified',
            ]))
        );

        $this->assertSame(ElectronicDocumentKind::TaxInvoice, $standard->kind());
        $this->assertTrue($standard->isStandard());
        $this->assertFalse($standard->isSimplified());
        $this->assertSame(InvoiceTransactionCode::Standard, $standard->transactionCode());

        $this->assertSame(ElectronicDocumentKind::SimplifiedTaxInvoice, $simplified->kind());
        $this->assertTrue($simplified->isSimplified());
        $this->assertFalse($simplified->isStandard());
        $this->assertSame(InvoiceTransactionCode::Simplified, $simplified->transactionCode());
    }

    public function test_einvoice_document_uses_snapshot_values_after_live_records_change(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Frozen Buyer', [
            'vat_number' => '300222222222223',
            'address' => 'Old Address',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Original Product',
            'slug' => 'original-product',
            'sku' => 'ORIG-1',
            'price' => 100,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
            'stock' => 0,
        ]);
        $this->updateCompany($workspace, ['company_name' => 'Original Co', 'vat_number' => '310000000000003']);

        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'skip_inventory' => true,
            'items' => [[
                'product_id' => $product->id,
                'product_name' => 'Original Product',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ], (int) $owner->id);
        $snapshot = $this->financeSnapshot($invoice);
        $before = app(EInvoiceFactory::class)->make($snapshot);

        DB::table('finance_invoices')->where('id', $invoice->id)->update([
            'subtotal' => 1,
            'tax_amount' => 1,
            'total' => 1,
        ]);
        $customer->update(['name' => 'Changed Buyer', 'vat_number' => '399999999999993', 'address' => 'New Address']);
        $product->update(['name' => 'Renamed Product', 'price' => 9]);
        $this->updateCompany($workspace, ['company_name' => 'Tomorrow Co', 'vat_number' => '320000000000003']);

        $after = app(EInvoiceFactory::class)->make($snapshot->fresh());

        $this->assertSame($before->toArray(), $after->toArray());
        $this->assertSame('Frozen Buyer', $after->buyer->name);
        $this->assertSame('300222222222223', $after->buyer->vatNumber);
        $this->assertSame('Original Product', $after->lines[0]->productName);
        $this->assertSame('Original Co', $after->seller->name);
        $this->assertSame('115.00', $after->totals->total);
        $this->assertSame('15.00', $after->tax->amount);
    }

    public function test_pos_einvoice_document_ignores_later_pos_and_customer_changes(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'POS Frozen', ['vat_number' => '300111111111113']);
        $item = $this->menuItem($workspace, 'Tea', 100);
        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'customer_id' => $customer->id,
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);
        $before = app(EInvoiceFactory::class)->make($snapshot);

        DB::table('pos_cashier_invoices')->where('id', $invoice->id)->update([
            'subtotal' => 1,
            'tax_amount' => 1,
            'total_amount' => 1,
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'subtotal' => 1,
            'tax_amount' => 1,
            'total_amount' => 1,
        ]);
        $customer->update(['name' => 'POS Changed', 'vat_number' => '399999999999993']);
        $item->update(['name' => 'Renamed Tea', 'price' => 3]);

        $after = app(EInvoiceFactory::class)->make($snapshot->fresh());

        $this->assertSame($before->toArray(), $after->toArray());
        $this->assertSame('POS Frozen', $after->buyer->name);
        $this->assertSame('Tea', $after->lines[0]->productName);
        $this->assertSame('115.00', $after->totals->total);
        $this->assertSame('15.00', $after->tax->amount);
    }

    public function test_finance_and_pos_tax_values_are_copied_not_recalculated(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Tax Copy Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id, [
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
        $financeSnapshot = $this->financeSnapshot($invoice);
        $financeDocument = app(EInvoiceFactory::class)->make($financeSnapshot);

        $this->assertSame(data_get($financeSnapshot->payload, 'tax.amount'), $financeDocument->tax->amount);
        $this->assertSame(data_get($financeSnapshot->payload, 'tax.rate'), $financeDocument->tax->rate);
        $this->assertSame(data_get($financeSnapshot->payload, 'totals.taxable_amount'), $financeDocument->totals->taxableAmount);
        $this->assertSame(ElectronicTaxClassification::Exempt, $financeDocument->tax->classification);
        $this->assertSame('تعليمي', $financeDocument->lines[0]->exemptionReason);

        $item = $this->menuItem($workspace, 'POS Tax Copy', 40);
        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $posInvoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $posSnapshot = $this->posSnapshot($posInvoice);
        $posDocument = app(EInvoiceFactory::class)->make($posSnapshot);

        $this->assertSame(data_get($posSnapshot->payload, 'tax.amount'), $posDocument->tax->amount);
        $this->assertSame(data_get($posSnapshot->payload, 'tax.configured_rate'), $posDocument->tax->rate);
        $this->assertSame(data_get($posSnapshot->payload, 'totals.total'), $posDocument->totals->total);

        $factorySource = file_get_contents(app_path('Services/EInvoicing/EInvoiceFactory.php'));
        $this->assertStringNotContainsString('TaxCalculationService', $factorySource);
        $this->assertStringNotContainsString('PosTaxCalculator', $factorySource);
        $this->assertStringNotContainsString('percentOf', $factorySource);
        $this->assertStringNotContainsString('(float)', $factorySource);
    }

    public function test_factory_maps_using_only_in_memory_snapshot_data(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Architecture Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()->findOrFail(
            $this->financeSnapshot($invoice)->id
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $document = app(EInvoiceFactory::class)->make($snapshot);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($document->snapshotIdentityMatches($snapshot));
        $this->assertSame('115.00', $document->totals->total);

        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            $this->assertStringNotContainsString('finance_invoices', $sql);
            $this->assertStringNotContainsString('finance_invoice_items', $sql);
            $this->assertStringNotContainsString('customers', $sql);
            $this->assertStringNotContainsString('products', $sql);
            $this->assertStringNotContainsString('finance_settings', $sql);
            $this->assertStringNotContainsString('from "workspaces"', $sql);
            $this->assertStringNotContainsString('from `workspaces`', $sql);
            $this->assertStringNotContainsString('from "orders"', $sql);
            $this->assertStringNotContainsString('from `orders`', $sql);
            $this->assertStringNotContainsString('order_items', $sql);
            $this->assertStringNotContainsString('finance_invoice_payments', $sql);
        }
    }

    public function test_cross_workspace_snapshot_mapping_is_rejected(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspaceA, 'Workspace A Buyer');
        $invoice = $this->issueFinance($workspaceA, $customer, (int) $ownerA->id);
        $snapshotId = $this->financeSnapshot($invoice)->id;
        $this->createWorkspaceOwner();

        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()->findOrFail($snapshotId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Electronic invoice documents must stay in the snapshot workspace.');
        app(EInvoiceFactory::class)->make($snapshot);
    }

    public function test_compliance_state_is_independent_and_transitions_are_guarded(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'State Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $document = app(EInvoiceFactory::class)->make($this->financeSnapshot($invoice));

        $this->assertSame('issued', $document->businessStatus);
        $this->assertNotNull($document->paymentStatus);
        $this->assertSame(ComplianceStatus::Ready, $document->complianceStatus);
        $this->assertNotSame($document->businessStatus, $document->complianceStatus->value);
        $this->assertNotSame($document->paymentStatus, $document->complianceStatus->value);
        $this->assertFalse(ComplianceStateMachine::canTransition(
            ComplianceStatus::Ready,
            ComplianceStatus::Cleared,
        ));

        $record = app(EInvoiceFactory::class)->persist($this->financeSnapshot($invoice));
        $record->transitionCompliance(ComplianceStatus::Generated);
        $this->assertSame(ComplianceStatus::Generated, $record->fresh()->compliance_status);

        $this->expectException(InvalidArgumentException::class);
        $record->fresh()->transitionCompliance(ComplianceStatus::Cleared);
    }

    public function test_persisted_document_is_idempotent_and_immutable(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Persist Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $snapshot = $this->financeSnapshot($invoice);
        $factory = app(EInvoiceFactory::class);

        $first = $factory->persist($snapshot);
        $second = $factory->persist($snapshot->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, EInvoiceDocumentRecord::withoutGlobalScopes()->count());
        $this->assertSame($snapshot->id, (int) $first->issued_document_snapshot_id);
        $this->assertSame($snapshot->source_type, $first->source_type);
        $this->assertSame((int) $snapshot->source_id, (int) $first->source_id);
        $this->assertSame(data_get($first->payload, 'totals.total'), '115.00');

        try {
            $first->update(['document_number' => 'FORGED']);
            $this->fail('Persisted electronic document accepted an arbitrary update.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            $first->update([
                'issued_document_snapshot_id' => $snapshot->id + 99,
                'source_id' => $snapshot->source_id + 99,
            ]);
            $this->fail('Source snapshot relationship was changed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            $first->delete();
            $this->fail('Persisted electronic document accepted a delete.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        $this->expectException(UniqueConstraintViolationException::class);
        EInvoiceDocumentRecord::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'issued_document_snapshot_id' => $snapshot->id,
            'source_type' => $snapshot->source_type,
            'source_id' => $snapshot->source_id,
            'document_kind' => ElectronicDocumentKind::TaxInvoice->value,
            'document_number' => 'DUP',
            'currency' => 'SAR',
            'compliance_status' => ComplianceStatus::Ready->value,
            'payload' => ['document' => ['number' => 'dup']],
        ]);
    }

    public function test_unsupported_source_type_is_rejected(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->issueFinance(
            $workspace,
            $this->makeCustomer($workspace, 'Unsupported Buyer'),
            (int) $owner->id
        );

        $snapshot = new IssuedDocumentSnapshot;
        $snapshot->forceFill([
            'id' => 999,
            'workspace_id' => $workspace->id,
            'source_type' => 'contract',
            'source_id' => 1,
            'document_number' => 'X',
            'payload' => ['document' => ['type' => 'contract']],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(EInvoiceFactory::class)->make($snapshot);
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
            'name' => 'Phase5 Workspace',
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

    private function financeSnapshot(FinanceInvoice $invoice): IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
    }

    private function noteSnapshot(FinanceCreditNote $note): IssuedDocumentSnapshot
    {
        $sourceType = $note->isCredit()
            ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
            : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE;

        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', $sourceType)
            ->where('source_id', $note->id)
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
