<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\EInvoicing\Xml\UblMapper;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\Finance\TaxProfileType;
use App\Models\Customer;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
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
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Services\Pos\PosOrderService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase5ASnapshotCompletenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_finance_standard_and_simplified_subtypes_are_preserved(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Subtype Buyer');

        $standard = $this->financeSnapshot($this->issueFinance($workspace, $customer, (int) $owner->id));
        $simplified = $this->financeSnapshot($this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_document_subtype' => 'simplified',
        ]));

        $this->assertSame('standard', data_get($standard->payload, 'document.tax_document_subtype'));
        $this->assertSame('standard', data_get($standard->payload, 'document.subtype'));
        $this->assertSame('simplified', data_get($simplified->payload, 'document.tax_document_subtype'));

        $standardDocument = app(EInvoiceFactory::class)->make($standard);
        $simplifiedDocument = app(EInvoiceFactory::class)->make($simplified);
        $this->assertSame(InvoiceTransactionCode::Standard, $standardDocument->transactionCode());
        $this->assertSame(InvoiceTransactionCode::Simplified, $simplifiedDocument->transactionCode());
        $this->assertTrue($standardDocument->isStandard());
        $this->assertTrue($simplifiedDocument->isSimplified());
    }

    public function test_credit_and_debit_notes_preserve_original_invoice_subtype(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Note Subtype Buyer');
        $standardInvoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $simplifiedInvoice = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_document_subtype' => 'simplified',
        ]);

        $credit = $this->issueNote($workspace, $standardInvoice, (int) $owner->id, 'credit', 'خصم تجاري');
        $debit = $this->issueNote($workspace, $simplifiedInvoice, (int) $owner->id, 'debit', 'رسوم إضافية');

        $creditSnapshot = $this->noteSnapshot($credit);
        $debitSnapshot = $this->noteSnapshot($debit);

        $this->assertSame('standard', data_get($creditSnapshot->payload, 'document.tax_document_subtype'));
        $this->assertSame($standardInvoice->invoice_number, data_get($creditSnapshot->payload, 'reference.invoice_number'));
        $this->assertSame('simplified', data_get($debitSnapshot->payload, 'document.tax_document_subtype'));
        $this->assertSame($simplifiedInvoice->invoice_number, data_get($debitSnapshot->payload, 'reference.invoice_number'));

        $creditDocument = app(EInvoiceFactory::class)->make($creditSnapshot);
        $debitDocument = app(EInvoiceFactory::class)->make($debitSnapshot);
        $this->assertSame(InvoiceTransactionCode::Standard, $creditDocument->transactionCode());
        $this->assertSame(InvoiceTransactionCode::Simplified, $debitDocument->transactionCode());
        $this->assertSame('خصم تجاري', $creditDocument->reason);
        $this->assertSame('رسوم إضافية', $debitDocument->reason);
        $this->assertSame($standardInvoice->invoice_number, $creditDocument->originalDocument?->invoiceNumber);
        $this->assertSame($simplifiedInvoice->invoice_number, $debitDocument->originalDocument?->invoiceNumber);
    }

    public function test_missing_subtype_is_not_guessed_for_pos_or_historical_notes(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->completeSeller($workspace);
        $item = $this->menuItem($workspace, 'POS Item', 50);
        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $posInvoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $posSnapshot = $this->posSnapshot($posInvoice);

        $this->assertNull(data_get($posSnapshot->payload, 'document.tax_document_subtype'));
        $this->assertNull(data_get($posSnapshot->payload, 'document.subtype'));
        $posDocument = app(EInvoiceFactory::class)->make($posSnapshot);
        $this->assertSame(ElectronicDocumentKind::PosCashierInvoice, $posDocument->kind());
        $this->assertNull($posDocument->typeCode());
        $this->assertNull($posDocument->transactionCode());

        $historical = new IssuedDocumentSnapshot;
        $historical->forceFill([
            'id' => 9001,
            'workspace_id' => $workspace->id,
            'source_type' => IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE,
            'source_id' => 1,
            'document_number' => 'CN-HIST',
            'payload' => [
                'document' => ['type' => 'credit', 'number' => 'CN-HIST'],
                'seller' => [],
                'buyer' => [],
                'lines' => [],
                'tax' => [],
                'totals' => [],
                'reference' => ['invoice_number' => 'INV-1'],
            ],
        ]);
        $historicalDocument = app(EInvoiceFactory::class)->make($historical);
        $this->assertNull($historicalDocument->transactionCode());
        $this->assertFalse($historicalDocument->isStandard());
        $this->assertFalse($historicalDocument->isSimplified());
    }

    public function test_seller_and_buyer_structured_addresses_are_frozen(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->completeSeller($workspace, [
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
            'country_code' => 'SA',
            'address_line' => 'HQ Line',
        ]);
        $customer = $this->makeCustomer($workspace, 'Address Buyer', [
            'vat_number' => '300111111111113',
            'address' => 'Free text address',
            'street' => 'Buyer Street',
            'building_number' => '22',
            'additional_number' => '9876',
            'district' => 'Al Balad',
            'city' => 'Jeddah',
            'postal_code' => '22222',
            'country_code' => 'SA',
        ]);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $snapshot = $this->financeSnapshot($invoice);

        $this->assertSame('King Fahd Road', data_get($snapshot->payload, 'seller.address.street'));
        $this->assertSame('1234', data_get($snapshot->payload, 'seller.address.building_number'));
        $this->assertNull(data_get($snapshot->payload, 'seller.address.additional_number'));
        $this->assertSame('Al Olaya', data_get($snapshot->payload, 'seller.address.district'));
        $this->assertSame('Riyadh', data_get($snapshot->payload, 'seller.address.city'));
        $this->assertSame('12345', data_get($snapshot->payload, 'seller.address.postal_code'));
        $this->assertSame('SA', data_get($snapshot->payload, 'seller.address.country_code'));
        $this->assertSame('Buyer Street', data_get($snapshot->payload, 'buyer.address.street'));
        $this->assertSame('22', data_get($snapshot->payload, 'buyer.address.building_number'));
        $this->assertSame('9876', data_get($snapshot->payload, 'buyer.address.additional_number'));
        $this->assertSame('Free text address', data_get($snapshot->payload, 'buyer.address.line'));

        $customer->update([
            'street' => 'Changed Street',
            'city' => 'Dammam',
            'vat_number' => '399999999999993',
        ]);
        $this->completeSeller($workspace, ['street' => 'Tomorrow Road', 'city' => 'Abha']);

        $document = app(EInvoiceFactory::class)->make($snapshot->fresh());
        $this->assertSame('King Fahd Road', $document->seller->address->street);
        $this->assertSame('Buyer Street', $document->buyer->address->street);
        $this->assertSame('Jeddah', $document->buyer->address->city);
        $this->assertSame('300111111111113', $document->buyer->vatNumber);
        $this->assertSame('Address Buyer', $document->buyer->name);
    }

    public function test_missing_address_components_remain_null(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Sparse Buyer', [
            'street' => 'Only Street',
        ]);
        $snapshot = $this->financeSnapshot($this->issueFinance($workspace, $customer, (int) $owner->id));
        $document = app(EInvoiceFactory::class)->make($snapshot);

        $this->assertSame('Only Street', $document->buyer->address->street);
        $this->assertNull($document->buyer->address->buildingNumber);
        $this->assertNull($document->buyer->address->district);
        $this->assertNull($document->buyer->address->postalCode);
        $this->assertNull($document->buyer->address->countryCode);
        $this->assertNull($document->seller->address->additionalNumber);
    }

    public function test_supply_date_is_captured_and_frozen_without_using_live_now(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Supply Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'supply_date' => '2026-08-15',
        ]);
        $snapshot = $this->financeSnapshot($invoice);

        $this->assertSame('2026-08-15', data_get($snapshot->payload, 'document.supply_date'));
        $this->assertNotSame($invoice->issue_date?->toDateString(), data_get($snapshot->payload, 'document.supply_date'));

        DB::table('finance_invoices')->where('id', $invoice->id)->update(['supply_date' => '2026-09-01']);
        $document = app(EInvoiceFactory::class)->make($snapshot->fresh());
        $this->assertSame('2026-08-15', $document->supplyDate);
        $this->assertNotSame(now()->toDateString(), $document->supplyDate);
    }

    public function test_missing_supply_date_stays_null_and_is_not_filled_from_issue_date(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'No Supply Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id);
        $snapshot = $this->financeSnapshot($invoice);

        $this->assertNull(data_get($snapshot->payload, 'document.supply_date'));
        $this->assertNull(app(EInvoiceFactory::class)->make($snapshot)->supplyDate);
        $this->assertNotNull(data_get($snapshot->payload, 'document.issue_date'));
    }

    public function test_pos_supply_date_is_not_inferred_from_closed_at(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'POS Supply', 40);
        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);

        $this->assertNull(data_get($snapshot->payload, 'document.supply_date'));
        $this->assertNotNull(data_get($snapshot->payload, 'document.issue_date'));
        $this->assertNull(app(EInvoiceFactory::class)->make($snapshot)->supplyDate);
    }

    public function test_unit_codes_are_preserved_and_labels_are_not_guessed(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Unit Buyer');
        $coded = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'items' => [[
                'product_name' => 'Hourly service',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
                'unit_code' => 'HUR',
            ]],
        ]);
        $label = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'items' => [[
                'product_name' => 'Box of widgets',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
                'unit_code' => 'piece',
            ]],
        ]);
        $plain = $this->issueFinance($workspace, $customer, (int) $owner->id);

        $this->assertSame('HUR', data_get($this->financeSnapshot($coded)->payload, 'lines.0.unit_code'));
        $this->assertSame('piece', data_get($this->financeSnapshot($label)->payload, 'lines.0.unit_code'));
        $this->assertNull(data_get($this->financeSnapshot($plain)->payload, 'lines.0.unit_code'));

        $this->assertSame('HUR', app(EInvoiceFactory::class)->make($this->financeSnapshot($coded))->lines[0]->unitCode);
        $this->assertSame('piece', app(EInvoiceFactory::class)->make($this->financeSnapshot($label))->lines[0]->unitCode);
        $this->assertNull(app(EInvoiceFactory::class)->make($this->financeSnapshot($plain))->lines[0]->unitCode);
        $this->assertNotContains(
            app(EInvoiceFactory::class)->make($this->financeSnapshot($plain))->lines[0]->unitCode,
            ['EA', 'PCE', 'H87']
        );
    }

    public function test_payment_business_methods_are_preserved_without_untdid_guessing(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Pay Buyer');
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'invoice_status' => 'draft',
        ]);
        FinanceInvoicePayment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'method' => 'card',
            'payment_date' => now()->toDateString(),
            'status' => 'posted',
        ]);
        $issued = app(InvoiceService::class)->issue($invoice->fresh(), (int) $owner->id, true);
        $snapshot = $this->financeSnapshot($issued);

        $this->assertSame(['card'], data_get($snapshot->payload, 'payment.business_methods'));
        $this->assertNull(data_get($snapshot->payload, 'payment.regulatory_code'));

        DB::table('finance_invoice_payments')->where('invoice_id', $issued->id)->update(['method' => 'cash']);
        $document = app(EInvoiceFactory::class)->make($snapshot->fresh());
        $this->assertSame(['card'], $document->payment['business_methods']);
        $this->assertNull($document->payment['regulatory_code']);
        $this->assertArrayNotHasKey('10', $document->payment);
    }

    public function test_pos_payment_methods_are_not_mapped_to_untdid(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $item = $this->menuItem($workspace, 'POS Pay', 40);
        $order = app(PosOrderService::class)->createPosOrder($workspace, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ], $owner);
        $invoice = app(PosOrderService::class)->createInvoiceFromOrder($order, (int) $owner->id);
        $snapshot = $this->posSnapshot($invoice);
        $document = app(EInvoiceFactory::class)->make($snapshot);

        $this->assertSame(['cashier'], data_get($snapshot->payload, 'payment.business_methods'));
        $this->assertSame(['cashier'], data_get($snapshot->payload, 'payment.payment_methods'));
        $this->assertNull(data_get($snapshot->payload, 'payment.regulatory_code'));
        $this->assertNull($document->payment['regulatory_code'] ?? null);
        $this->assertSame(ElectronicDocumentKind::PosCashierInvoice, $document->kind());
    }

    public function test_exemption_code_and_reason_are_preserved_and_not_fabricated(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->makeCustomer($workspace, 'Exempt Buyer');
        $withCode = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_profile_type' => TaxProfileType::Exempt->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'Exempt',
                'quantity' => 1,
                'unit_price' => 30,
                'discount' => 0,
                'tax_type' => TaxProfileType::Exempt->value,
                'exemption_reason' => 'تعليمي',
                'exemption_code' => 'VATEX-SA-EDU',
            ]],
        ]);
        $withoutCode = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'tax_profile_type' => TaxProfileType::Exempt->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'Exempt no code',
                'quantity' => 1,
                'unit_price' => 30,
                'discount' => 0,
                'tax_type' => TaxProfileType::Exempt->value,
                'exemption_reason' => 'تعليمي',
            ]],
        ]);

        $codedDocument = app(EInvoiceFactory::class)->make($this->financeSnapshot($withCode));
        $plainDocument = app(EInvoiceFactory::class)->make($this->financeSnapshot($withoutCode));
        $this->assertSame('VATEX-SA-EDU', $codedDocument->lines[0]->exemptionCode);
        $this->assertSame('تعليمي', $codedDocument->lines[0]->exemptionReason);
        $this->assertSame('تعليمي', $plainDocument->lines[0]->exemptionReason);
        $this->assertNull($plainDocument->lines[0]->exemptionCode);
    }

    public function test_mutating_live_records_does_not_change_snapshot_projection_or_xml(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->completeSeller($workspace);
        $customer = $this->makeCustomer($workspace, 'Frozen Completeness', [
            'vat_number' => '300111111111113',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
        ]);
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Original Product',
            'slug' => 'original-product-5a',
            'sku' => 'ORIG-5A',
            'price' => 100,
            'currency' => 'SAR',
            'status' => 'active',
            'inventory_tracking' => false,
            'stock' => 0,
        ]);
        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'supply_date' => '2026-08-20',
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
                'unit_code' => 'HUR',
            ]],
        ], (int) $owner->id);
        $snapshot = $this->financeSnapshot($invoice);
        $beforePayload = $snapshot->payload;
        $beforeDocument = app(EInvoiceFactory::class)->make($snapshot);
        $beforeXml = app(UblMapper::class)->map($beforeDocument);

        DB::table('finance_invoices')->where('id', $invoice->id)->update([
            'subtotal' => 1,
            'tax_amount' => 1,
            'total' => 1,
            'supply_date' => '2026-09-09',
            'notes' => 'changed',
        ]);
        DB::table('finance_invoice_items')->where('invoice_id', $invoice->id)->update([
            'product_name' => 'Renamed',
            'unit_code' => 'PCE',
        ]);
        $customer->update(['name' => 'Changed Buyer', 'street' => 'New Street', 'city' => 'Abha']);
        $product->update(['name' => 'Renamed Product', 'price' => 9]);
        $this->completeSeller($workspace, ['company_name' => 'Tomorrow Co', 'street' => 'New Seller Street']);
        FinanceInvoicePayment::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'status' => 'posted',
        ]);

        $afterSnapshot = $snapshot->fresh();
        $afterDocument = app(EInvoiceFactory::class)->make($afterSnapshot);
        $afterXml = app(UblMapper::class)->map($afterDocument);

        $this->assertSame($beforePayload, $afterSnapshot->payload);
        $this->assertSame($beforeDocument->toArray(), $afterDocument->toArray());
        $this->assertSame($beforeXml, $afterXml);
        $this->assertSame('Original Product', $afterDocument->lines[0]->productName);
        $this->assertSame('HUR', $afterDocument->lines[0]->unitCode);
        $this->assertSame('2026-08-20', $afterDocument->supplyDate);
        $this->assertSame('Frozen Completeness', $afterDocument->buyer->name);
        $this->assertSame('Buyer Street', $afterDocument->buyer->address->street);
        $this->assertSame('Issued Co', $afterDocument->seller->name);
        $this->assertSame([], $afterDocument->payment['business_methods']);
    }

    public function test_xml_consumes_new_snapshot_fields_without_live_queries(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->completeSeller($workspace);
        $customer = $this->makeCustomer($workspace, 'XML Buyer', [
            'vat_number' => '300111111111113',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
        ]);
        $invoice = $this->issueFinance($workspace, $customer, (int) $owner->id, [
            'supply_date' => '2026-08-21',
            'items' => [[
                'product_name' => 'خدمة فوترة',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => TaxProfileType::Standard->value,
                'unit_code' => 'HUR',
            ]],
        ]);
        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()->findOrFail(
            $this->financeSnapshot($invoice)->id
        );
        $document = app(EInvoiceFactory::class)->make($snapshot);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $generated = app(EInvoiceXmlGenerator::class)->generate($document, true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $xml = $generated->xml;

        $this->assertTrue($generated->schemaValid, implode('; ', $generated->validationErrors));
        $this->assertStringContainsString('<cbc:ActualDeliveryDate>2026-08-21</cbc:ActualDeliveryDate>', $xml);
        $this->assertStringContainsString('unitCode="HUR"', $xml);
        $this->assertStringContainsString('King Fahd Road', $xml);
        $this->assertStringContainsString('Buyer Street', $xml);
        $this->assertStringNotContainsString('PaymentMeansCode', $xml);
        $this->assertSame('2026-08-21', $document->supplyDate);

        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            foreach ([
                'finance_invoices',
                'finance_invoice_items',
                'customers',
                'products',
                'finance_settings',
                'from "workspaces"',
                'from `workspaces`',
                'from "orders"',
                'from `orders`',
                'order_items',
                'finance_invoice_payments',
            ] as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }
        }
    }

    public function test_purchase_invoices_are_not_sales_einvoices(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $supplier = FinanceSupplier::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Supplier Co',
            'vat_number' => '310000000000003',
            'status' => 'active',
        ]);
        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'purchase',
            'supplier_id' => $supplier->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => 'standard',
            'tax_rate' => 15,
            'items' => [[
                'product_name' => 'Purchase item',
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 15,
                'tax_type' => 'standard',
            ]],
        ], (int) $owner->id);
        $document = app(EInvoiceFactory::class)->make($this->financeSnapshot($invoice));

        $this->assertSame('purchase', data_get($this->financeSnapshot($invoice)->payload, 'document.type'));
        $this->assertSame(ElectronicDocumentKind::PurchaseInvoice, $document->kind());
        $this->assertNull($document->typeCode());
        $this->expectException(EInvoiceXmlMappingException::class);
        app(UblMapper::class)->map($document);
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
            'name' => 'Phase5A Workspace',
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
    private function completeSeller(Workspace $workspace, array $attributes = []): void
    {
        FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->update(array_merge([
                'company_name' => 'Issued Co',
                'vat_number' => '310000000000003',
                'commercial_registration' => '1010000000',
                'street' => 'King Fahd Road',
                'building_number' => '1234',
                'district' => 'Al Olaya',
                'city' => 'Riyadh',
                'postal_code' => '12345',
                'country_code' => 'SA',
            ], $attributes));
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

    private function issueNote(
        Workspace $workspace,
        FinanceInvoice $invoice,
        int $actorId,
        string $type,
        string $reason,
    ): FinanceCreditNote {
        return app(CreditNoteService::class)->create($workspace, $invoice, [
            'type' => $type,
            'reason' => $reason,
            'issue_date' => now()->toDateString(),
            'status' => 'issued',
            'items' => [[
                'product_name' => $type === 'credit' ? 'تعويض' : 'رسوم',
                'quantity' => 1,
                'unit_price' => $type === 'credit' ? 20 : 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ], $actorId);
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
