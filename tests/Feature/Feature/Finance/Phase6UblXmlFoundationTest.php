<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\EInvoiceAddress;
use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\EInvoiceLine;
use App\EInvoicing\EInvoiceParty;
use App\EInvoicing\EInvoiceTax;
use App\EInvoicing\EInvoiceTotals;
use App\EInvoicing\InvoiceType;
use App\EInvoicing\OriginalDocumentReference;
use App\EInvoicing\Xml\DocumentUuid;
use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\EInvoicing\Xml\UblMapper;
use App\EInvoicing\Xml\UblNamespaces;
use App\EInvoicing\Xml\Validation\SchematronValidator;
use App\EInvoicing\Xml\Validation\XsdValidator;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\ElectronicTaxClassification;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Models\Customer;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\EInvoiceFactory;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase6UblXmlFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_standard_tax_invoice_xml_structure(): void
    {
        $xml = $this->generate($this->document());
        $xp = $xml['xpath'];

        $this->assertSame('Invoice', $xml['root']);
        $this->assertSame(UblNamespaces::INVOICE, $xml['namespace']);
        $this->assertSame(UblNamespaces::CAC, $xp->document->documentElement->lookupNamespaceURI('cac'));
        $this->assertSame(UblNamespaces::CBC, $xp->document->documentElement->lookupNamespaceURI('cbc'));
        $this->assertSame('388', $this->value($xp, '//cbc:InvoiceTypeCode'));
        $this->assertSame('0100000', $this->attr($xp, '//cbc:InvoiceTypeCode', 'name'));
        $this->assertSame('INV-100', $this->value($xp, '//cbc:ID'));
        $this->assertSame('2026-09-01', $this->value($xp, '//cbc:IssueDate'));
        $this->assertSame('10:15:30', $this->value($xp, '//cbc:IssueTime'));
        $this->assertSame('SAR', $this->value($xp, '//cbc:DocumentCurrencyCode'));
        $this->assertSame('reporting:1.0', $this->value($xp, '//cbc:ProfileID'));
        $this->assertSame('2.1', $this->value($xp, '//cbc:UBLVersionID'));
    }

    public function test_simplified_tax_invoice_uses_transaction_code_from_domain(): void
    {
        $document = $this->document(
            kind: ElectronicDocumentKind::SimplifiedTaxInvoice,
            transaction: InvoiceTransactionCode::Simplified,
            buyerWalkIn: true,
        );
        $xml = $this->generate($document);

        $this->assertSame('388', $this->value($xml['xpath'], '//cbc:InvoiceTypeCode'));
        $this->assertSame('0200000', $this->attr($xml['xpath'], '//cbc:InvoiceTypeCode', 'name'));
        $this->assertTrue($document->isSimplified());
    }

    public function test_seller_and_buyer_and_structured_address_map_from_document_only(): void
    {
        $xml = $this->generate($this->document());
        $xp = $xml['xpath'];

        $this->assertSame('Issued Co', $this->value($xp, '//cac:AccountingSupplierParty//cbc:RegistrationName'));
        $this->assertSame('310000000000003', $this->value($xp, '//cac:AccountingSupplierParty//cbc:CompanyID'));
        $this->assertSame('CRN', $this->attr($xp, '//cac:AccountingSupplierParty//cac:PartyIdentification/cbc:ID', 'schemeID'));
        $this->assertSame('1234', $this->value($xp, '//cac:AccountingSupplierParty//cbc:BuildingNumber'));
        $this->assertSame('King Fahd Road', $this->value($xp, '//cac:AccountingSupplierParty//cbc:StreetName'));
        $this->assertSame('Al Olaya', $this->value($xp, '//cac:AccountingSupplierParty//cbc:CitySubdivisionName'));
        $this->assertSame('Riyadh', $this->value($xp, '//cac:AccountingSupplierParty//cbc:CityName'));
        $this->assertSame('12345', $this->value($xp, '//cac:AccountingSupplierParty//cbc:PostalZone'));
        $this->assertSame('SA', $this->value($xp, '//cac:AccountingSupplierParty//cbc:IdentificationCode'));

        $this->assertSame('E-Invoice Buyer', $this->value($xp, '//cac:AccountingCustomerParty//cbc:RegistrationName'));
        $this->assertSame('300111111111113', $this->value($xp, '//cac:AccountingCustomerParty//cbc:CompanyID'));
        $this->assertSame('Buyer Street', $this->value($xp, '//cac:AccountingCustomerParty//cbc:StreetName'));
        $this->assertFalse($this->has($xp, '//cac:AccountingCustomerParty//cbc:BuildingNumber'));
    }

    public function test_missing_optional_buyer_fields_are_not_fabricated(): void
    {
        $xml = $this->generate($this->document(buyerVat: null, buyerPostal: null));
        $xp = $xml['xpath'];

        $this->assertFalse($this->has($xp, '//cac:AccountingCustomerParty//cbc:CompanyID'));
        $this->assertFalse($this->has($xp, '//cac:AccountingCustomerParty//cbc:PostalZone'));
        $this->assertFalse($this->has($xp, '//cbc:PaymentMeansCode'));
        $this->assertStringNotContainsString('PCE', $xml['xml']);
        $this->assertStringNotContainsString('unitCode', $xml['xml']);
    }

    public function test_lines_quantities_prices_discounts_and_item_names(): void
    {
        $document = $this->document(lines: [
            $this->line('Service A', '2.000', '50.00', '5.00', '95.00', '14.25', '109.25'),
            $this->line('Service B', '1.000', '10.00', '0.00', '10.00', '1.50', '11.50'),
        ], totals: new EInvoiceTotals('110.00', '5.00', '105.00', '15.75', '120.75', '0.00', '120.75'));
        $xml = $this->generate($document);
        $xp = $xml['xpath'];

        $this->assertSame('2.000', $this->value($xp, '//cac:InvoiceLine[cbc:ID="1"]/cbc:InvoicedQuantity'));
        $this->assertSame('50.00', $this->value($xp, '//cac:InvoiceLine[cbc:ID="1"]//cbc:PriceAmount'));
        $this->assertSame('5.00', $this->value($xp, '//cac:InvoiceLine[cbc:ID="1"]/cac:AllowanceCharge/cbc:Amount'));
        $this->assertSame('95.00', $this->value($xp, '//cac:InvoiceLine[cbc:ID="1"]/cbc:LineExtensionAmount'));
        $this->assertSame('Service A', $this->value($xp, '//cac:InvoiceLine[cbc:ID="1"]//cbc:Name'));
        $this->assertSame('Service B', $this->value($xp, '//cac:InvoiceLine[cbc:ID="2"]//cbc:Name'));
        $this->assertSame('105.00', $this->value($xp, '//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
        $this->assertSame('120.75', $this->value($xp, '//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'));
        $this->assertSame('120.75', $this->value($xp, '//cac:LegalMonetaryTotal/cbc:PayableAmount'));
        $this->assertSame('5.00', $this->value($xp, '//cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount'));
    }

    public function test_standard_zero_rated_exempt_and_out_of_scope_tax_mapping(): void
    {
        $standard = $this->generate($this->document());
        $this->assertSame('S', $this->value($standard['xpath'], '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'));
        $this->assertSame('15.00', $this->value($standard['xpath'], '//cac:TaxTotal/cbc:TaxAmount'));
        $this->assertSame('100.00', $this->value($standard['xpath'], '//cac:TaxSubtotal/cbc:TaxableAmount'));

        $zero = $this->generate($this->document(
            tax: new EInvoiceTax(ElectronicTaxClassification::ZeroRated, '0.00', '0.00', 'exclusive', null),
            totals: new EInvoiceTotals('100.00', '0.00', '100.00', '0.00', '100.00', '0.00', '100.00'),
            lines: [$this->line('Zero', '1.000', '100.00', '0.00', '100.00', '0.00', '100.00', ElectronicTaxClassification::ZeroRated, '0.00')],
        ));
        $this->assertSame('Z', $this->value($zero['xpath'], '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'));
        $this->assertSame('0.00', $this->value($zero['xpath'], '//cac:TaxTotal/cbc:TaxAmount'));

        $exempt = $this->generate($this->document(
            tax: new EInvoiceTax(ElectronicTaxClassification::Exempt, '0.00', '0.00', 'exclusive', null),
            totals: new EInvoiceTotals('30.00', '0.00', '30.00', '0.00', '30.00', '0.00', '30.00'),
            lines: [$this->line('Exempt', '1.000', '30.00', '0.00', '30.00', '0.00', '30.00', ElectronicTaxClassification::Exempt, '0.00', 'تعليمي', 'VATEX-SA-EDU')],
        ));
        $this->assertSame('E', $this->value($exempt['xpath'], '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'));
        $this->assertSame('VATEX-SA-EDU', $this->value($exempt['xpath'], '//cbc:TaxExemptionReasonCode'));

        $oos = $this->generate($this->document(
            tax: new EInvoiceTax(ElectronicTaxClassification::OutOfScope, '0.00', '0.00', 'exclusive', null),
            totals: new EInvoiceTotals('20.00', '0.00', '20.00', '0.00', '20.00', '0.00', '20.00'),
            lines: [$this->line('OOS', '1.000', '20.00', '0.00', '20.00', '0.00', '20.00', ElectronicTaxClassification::OutOfScope, '0.00', 'Case note', 'VATEX-SA-OOS')],
        ));
        $this->assertSame('O', $this->value($oos['xpath'], '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'));
        $this->assertSame('VATEX-SA-OOS', $this->value($oos['xpath'], '//cbc:TaxExemptionReasonCode'));
    }

    public function test_rounding_values_are_copied_not_recalculated(): void
    {
        $document = $this->document(
            tax: new EInvoiceTax(ElectronicTaxClassification::Standard, '15.00', '0.01', 'exclusive', null),
            totals: new EInvoiceTotals('0.05', '0.00', '0.05', '0.01', '0.06', '0.00', '0.06'),
            lines: [$this->line('Penny', '1.000', '0.05', '0.00', '0.05', '0.01', '0.06')],
        );
        $xml = $this->generate($document);

        $this->assertSame('0.01', $this->value($xml['xpath'], '//cac:TaxTotal/cbc:TaxAmount'));
        $this->assertSame('0.05', $this->value($xml['xpath'], '//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
        $this->assertSame('0.06', $this->value($xml['xpath'], '//cac:LegalMonetaryTotal/cbc:PayableAmount'));
        $source = file_get_contents(app_path('EInvoicing/Xml/UblMapper.php'));
        $this->assertStringNotContainsString('TaxCalculationService', $source);
        $this->assertStringNotContainsString('PosTaxCalculator', $source);
        $this->assertStringNotContainsString('percentOf', $source);
    }

    public function test_credit_and_debit_notes_and_original_reference(): void
    {
        $credit = $this->document(
            kind: ElectronicDocumentKind::CreditNote,
            type: InvoiceTypeCode::CreditNote,
            number: 'CN-1',
            reason: 'خصم تجاري',
            original: new OriginalDocumentReference(10, 'INV-100', '2026-09-01', 'sales'),
            tax: new EInvoiceTax(ElectronicTaxClassification::Standard, '15.00', '3.00', 'exclusive', null),
            totals: new EInvoiceTotals('20.00', '0.00', '20.00', '3.00', '23.00', null, '23.00'),
            lines: [$this->line('تعويض', '1.000', '20.00', '0.00', '20.00', '3.00', '23.00')],
        );
        $creditXml = $this->generate($credit);
        $this->assertSame('CreditNote', $creditXml['root']);
        $this->assertSame(UblNamespaces::CREDIT_NOTE, $creditXml['namespace']);
        $this->assertSame('381', $this->value($creditXml['xpath'], '//cbc:CreditNoteTypeCode'));
        $this->assertSame('0100000', $this->attr($creditXml['xpath'], '//cbc:CreditNoteTypeCode', 'name'));
        $this->assertSame('INV-100', $this->value($creditXml['xpath'], '//cac:BillingReference//cbc:ID'));
        $this->assertSame('2026-09-01', $this->value($creditXml['xpath'], '//cac:BillingReference//cbc:IssueDate'));
        $this->assertSame('1.000', $this->value($creditXml['xpath'], '//cbc:CreditedQuantity'));

        $debit = $this->document(
            kind: ElectronicDocumentKind::DebitNote,
            type: InvoiceTypeCode::DebitNote,
            number: 'DN-1',
            reason: 'رسوم إضافية',
            original: new OriginalDocumentReference(10, 'INV-100', '2026-09-01', 'sales'),
            tax: new EInvoiceTax(ElectronicTaxClassification::Standard, '15.00', '1.50', 'exclusive', null),
            totals: new EInvoiceTotals('10.00', '0.00', '10.00', '1.50', '11.50', null, '11.50'),
            lines: [$this->line('رسوم', '1.000', '10.00', '0.00', '10.00', '1.50', '11.50')],
        );
        $debitXml = $this->generate($debit);
        $this->assertSame('Invoice', $debitXml['root']);
        $this->assertSame('383', $this->value($debitXml['xpath'], '//cbc:InvoiceTypeCode'));
        $this->assertSame('INV-100', $this->value($debitXml['xpath'], '//cac:BillingReference//cbc:ID'));
    }

    public function test_simplified_credit_and_debit_notes_when_domain_has_transaction_code(): void
    {
        $credit = $this->document(
            kind: ElectronicDocumentKind::SimplifiedCreditNote,
            type: InvoiceTypeCode::CreditNote,
            transaction: InvoiceTransactionCode::Simplified,
            number: 'CN-S',
            buyerWalkIn: true,
            reason: 'Return',
            original: new OriginalDocumentReference(10, 'INV-100', '2026-09-01', 'sales'),
        );
        $xml = $this->generate($credit);
        $this->assertSame('CreditNote', $xml['root']);
        $this->assertSame('0200000', $this->attr($xml['xpath'], '//cbc:CreditNoteTypeCode', 'name'));
    }

    public function test_same_document_produces_equivalent_xml(): void
    {
        $document = $this->document();
        $first = app(UblMapper::class)->map($document);
        $second = app(UblMapper::class)->map($document);
        $this->assertSame($first, $second);
        $this->assertSame(DocumentUuid::fromDocument($document), DocumentUuid::fromDocument($document));
    }

    public function test_xml_generation_does_not_query_live_business_tables(): void
    {
        $document = $this->document();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(UblMapper::class)->map($document);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            foreach ([
                'finance_invoices',
                'finance_invoice_items',
                'customers',
                'products',
                'from "workspaces"',
                'from `workspaces`',
                'finance_settings',
                'from "orders"',
                'from `orders`',
                'order_items',
                'contracts',
            ] as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }
        }
    }

    public function test_valid_xml_passes_official_ubl_xsd_and_invalid_xml_fails(): void
    {
        $valid = $this->generate($this->document(), validate: true);
        $this->assertTrue($valid->schemaValid, implode('; ', $valid->validationErrors));

        $invalid = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">
    <NotAUblElement>broken</NotAUblElement>
</Invoice>
XML;
        $errors = app(XsdValidator::class)->validate($invalid);
        $this->assertNotSame([], $errors);
    }

    public function test_schematron_infrastructure_reports_failures(): void
    {
        $valid = $this->generate($this->document());
        $this->assertSame([], app(SchematronValidator::class)->validate($valid['xml']));

        $invalid = '<?xml version="1.0"?><WrongRoot xmlns="urn:example:invalid"/>';
        $errors = app(SchematronValidator::class)->validate($invalid);
        $this->assertNotSame([], $errors);
    }

    public function test_missing_transaction_code_and_incomplete_seller_fail_clearly(): void
    {
        $unclassified = $this->document();
        $unclassified = new EInvoiceDocument(
            workspaceId: $unclassified->workspaceId,
            sourceSnapshotId: $unclassified->sourceSnapshotId,
            sourceType: $unclassified->sourceType,
            sourceId: $unclassified->sourceId,
            invoiceType: new InvoiceType(ElectronicDocumentKind::CreditNote, InvoiceTypeCode::CreditNote, null),
            documentNumber: $unclassified->documentNumber,
            issueDate: $unclassified->issueDate,
            issuedAt: $unclassified->issuedAt,
            currency: $unclassified->currency,
            seller: $unclassified->seller,
            buyer: $unclassified->buyer,
            lines: $unclassified->lines,
            tax: $unclassified->tax,
            totals: $unclassified->totals,
            originalDocument: new OriginalDocumentReference(1, 'INV-1', '2026-09-01', 'sales'),
            businessStatus: $unclassified->businessStatus,
            paymentStatus: $unclassified->paymentStatus,
            complianceStatus: $unclassified->complianceStatus,
            payment: $unclassified->payment,
            sourceMetadata: $unclassified->sourceMetadata,
            reason: 'Reason',
        );

        try {
            app(UblMapper::class)->map($unclassified);
            $this->fail('Unclassified credit note was mapped.');
        } catch (EInvoiceXmlMappingException $exception) {
            $this->assertStringContainsString('Transaction code is missing', $exception->getMessage());
        }

        $incompleteSeller = $this->document(sellerComplete: false);
        $this->expectException(EInvoiceXmlMappingException::class);
        $this->expectExceptionMessage('Seller address');
        app(UblMapper::class)->map($incompleteSeller);
    }

    public function test_factory_document_can_generate_xml_without_reading_live_tables(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $this->updateCompany($workspace, [
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
        $customer = $this->makeCustomer($workspace, 'E-Invoice Buyer', [
            'vat_number' => '300111111111113',
            'street' => 'Buyer Street',
            'city' => 'Jeddah',
            'country_code' => 'SA',
        ]);
        $invoice = app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
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
        ], (int) $owner->id);
        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->firstOrFail();
        $document = app(EInvoiceFactory::class)->make($snapshot);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $generated = app(EInvoiceXmlGenerator::class)->generate($document);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('Invoice', $generated->rootLocalName);
        $this->assertSame($invoice->invoice_number, $generated->documentNumber);
        $this->assertSame('15.00', $this->value($this->xpath($generated->xml)['xpath'], '//cac:TaxTotal/cbc:TaxAmount'));
        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            $this->assertStringNotContainsString('finance_invoices', $sql);
            $this->assertStringNotContainsString('customers', $sql);
            $this->assertStringNotContainsString('products', $sql);
            $this->assertStringNotContainsString('finance_settings', $sql);
            $this->assertStringNotContainsString('from "orders"', $sql);
            $this->assertStringNotContainsString('from `orders`', $sql);
            $this->assertStringNotContainsString('order_items', $sql);
            $this->assertStringNotContainsString('finance_invoice_payments', $sql);
        }

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
        $noteDocument = app(EInvoiceFactory::class)->make(
            IssuedDocumentSnapshot::withoutGlobalScopes()
                ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE)
                ->where('source_id', $credit->id)
                ->firstOrFail()
        );
        $this->assertSame(InvoiceTransactionCode::Standard, $noteDocument->transactionCode());
        $this->assertSame('خصم تجاري', $noteDocument->reason);
        $creditXml = app(UblMapper::class)->map($noteDocument);
        $this->assertStringContainsString('CreditNote', $creditXml);
        $this->assertStringContainsString('0100000', $creditXml);
        $this->assertStringContainsString($invoice->invoice_number, $creditXml);
    }

    /**
     * @return array{xml: string, xpath: DOMXPath, root: string, namespace: ?string}
     */
    private function generate(EInvoiceDocument $document, bool $validate = false): array|object
    {
        if ($validate) {
            return app(EInvoiceXmlGenerator::class)->generate($document, true);
        }

        $xml = app(UblMapper::class)->map($document);
        $parsed = $this->xpath($xml);

        return [
            'xml' => $xml,
            'xpath' => $parsed['xpath'],
            'root' => $parsed['root'],
            'namespace' => $parsed['namespace'],
        ];
    }

    /**
     * @return array{xpath: DOMXPath, root: string, namespace: ?string, document: DOMDocument}
     */
    private function xpath(string $xml): array
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cbc', UblNamespaces::CBC);
        $xpath->registerNamespace('cac', UblNamespaces::CAC);
        $xpath->registerNamespace('ubl', (string) $document->documentElement?->namespaceURI);

        return [
            'xpath' => $xpath,
            'root' => (string) $document->documentElement?->localName,
            'namespace' => $document->documentElement?->namespaceURI,
            'document' => $document,
        ];
    }

    private function value(DOMXPath $xpath, string $query): string
    {
        return trim((string) $xpath->evaluate('string('.$query.')'));
    }

    private function attr(DOMXPath $xpath, string $query, string $attribute): string
    {
        $node = $xpath->query($query)->item(0);

        return $node?->attributes?->getNamedItem($attribute)?->nodeValue ?? '';
    }

    private function has(DOMXPath $xpath, string $query): bool
    {
        return $xpath->query($query)->length > 0;
    }

    private function document(
        ElectronicDocumentKind $kind = ElectronicDocumentKind::TaxInvoice,
        InvoiceTypeCode $type = InvoiceTypeCode::TaxInvoice,
        InvoiceTransactionCode $transaction = InvoiceTransactionCode::Standard,
        string $number = 'INV-100',
        bool $sellerComplete = true,
        bool $buyerWalkIn = false,
        ?string $buyerVat = '300111111111113',
        ?string $buyerPostal = '22222',
        ?string $reason = null,
        ?OriginalDocumentReference $original = null,
        ?EInvoiceTax $tax = null,
        ?EInvoiceTotals $totals = null,
        ?array $lines = null,
    ): EInvoiceDocument {
        $sellerAddress = $sellerComplete
            ? new EInvoiceAddress(null, '1234', 'King Fahd Road', 'Al Olaya', 'Riyadh', '12345', 'SA', '5678')
            : new EInvoiceAddress('Riyadh', null, null, null, 'Riyadh', null, null, null);

        return new EInvoiceDocument(
            workspaceId: 1,
            sourceSnapshotId: 99,
            sourceType: IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            sourceId: 7,
            invoiceType: new InvoiceType($kind, $type, $transaction),
            documentNumber: $number,
            issueDate: '2026-09-01',
            issuedAt: '2026-09-01T10:15:30+03:00',
            currency: 'SAR',
            seller: new EInvoiceParty(
                'company',
                'Issued Co',
                '310000000000003',
                '1010000000',
                $sellerAddress,
                '0111111111',
                'seller@example.com',
            ),
            buyer: new EInvoiceParty(
                $buyerWalkIn ? 'anonymous' : 'customer',
                $buyerWalkIn ? null : 'E-Invoice Buyer',
                $buyerVat,
                null,
                new EInvoiceAddress(null, null, 'Buyer Street', 'Al Balad', 'Jeddah', $buyerPostal, 'SA', null),
                null,
                null,
                $buyerWalkIn,
            ),
            lines: $lines ?? [$this->line('خدمة فوترة', '1.000', '100.00', '0.00', '100.00', '15.00', '115.00')],
            tax: $tax ?? new EInvoiceTax(ElectronicTaxClassification::Standard, '15.00', '15.00', 'exclusive', null),
            totals: $totals ?? new EInvoiceTotals('100.00', '0.00', '100.00', '15.00', '115.00', '0.00', '115.00'),
            originalDocument: $original,
            businessStatus: 'issued',
            paymentStatus: 'unpaid',
            complianceStatus: ComplianceStatus::Ready,
            payment: [],
            sourceMetadata: [],
            reason: $reason,
        );
    }

    private function line(
        string $name,
        string $qty,
        string $price,
        string $discount,
        string $taxable,
        string $tax,
        string $total,
        ElectronicTaxClassification $classification = ElectronicTaxClassification::Standard,
        string $rate = '15.00',
        ?string $exemptionReason = null,
        ?string $exemptionCode = null,
    ): EInvoiceLine {
        return new EInvoiceLine(
            $name,
            $name,
            $qty,
            $price,
            $discount,
            $taxable,
            $classification,
            $rate,
            $tax,
            $total,
            $exemptionReason,
            $exemptionCode,
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
            'name' => 'Phase6 Workspace',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);
        foreach (['finance', 'products', 'customers'] as $feature) {
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
}
