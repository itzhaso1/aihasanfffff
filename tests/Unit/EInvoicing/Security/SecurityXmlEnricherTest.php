<?php

namespace Tests\Unit\EInvoicing\Security;

use App\EInvoicing\EInvoiceAddress;
use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\EInvoiceLine;
use App\EInvoicing\EInvoiceParty;
use App\EInvoicing\EInvoiceTax;
use App\EInvoicing\EInvoiceTotals;
use App\EInvoicing\InvoiceType;
use App\EInvoicing\Security\Icv;
use App\EInvoicing\Security\Pih;
use App\EInvoicing\Xml\UblMapper;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\ElectronicTaxClassification;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\EInvoicing\Security\SecurityXmlEnricher;
use PHPUnit\Framework\TestCase;

class SecurityXmlEnricherTest extends TestCase
{
    public function test_icv_and_pih_are_injected_before_the_supplier_party(): void
    {
        $xml = (new EInvoiceXmlGenerator(new UblMapper))->generate($this->document());
        $enriched = (new SecurityXmlEnricher)->enrich($xml, Icv::fromInt(7), Pih::firstDocument());

        $this->assertStringContainsString('>ICV</cbc:ID>', $enriched);
        $this->assertStringContainsString('>7</cbc:UUID>', $enriched);
        $this->assertStringContainsString(Pih::FIRST_DOCUMENT, $enriched);
        $this->assertNotFalse(strpos($enriched, '>ICV</cbc:ID>'));
        $this->assertTrue(strpos($enriched, '>ICV</cbc:ID>') < strpos($enriched, 'AccountingSupplierParty'));
        $this->assertStringNotContainsString('>QR</cbc:ID>', $enriched);
        $this->assertGeneratedXmlHasNoXmlCoreAttributes($xml->xml);
        $this->assertGeneratedXmlHasNoXmlCoreAttributes($enriched);
    }

    public function test_ubl_mapper_output_does_not_emit_xml_core_attributes(): void
    {
        $xml = (new EInvoiceXmlGenerator(new UblMapper))->generate($this->document());
        $this->assertGeneratedXmlHasNoXmlCoreAttributes($xml->xml);
    }

    private function document(): EInvoiceDocument
    {
        return new EInvoiceDocument(
            workspaceId: 1,
            sourceSnapshotId: 99,
            sourceType: IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            sourceId: 7,
            invoiceType: new InvoiceType(
                ElectronicDocumentKind::TaxInvoice,
                InvoiceTypeCode::TaxInvoice,
                InvoiceTransactionCode::Standard,
            ),
            documentNumber: 'INV-100',
            issueDate: '2026-09-01',
            issuedAt: '2026-09-01T10:15:30+03:00',
            currency: 'SAR',
            seller: new EInvoiceParty(
                'company',
                'Issued Co',
                '310000000000003',
                '1010000000',
                new EInvoiceAddress(null, '1234', 'King Fahd Road', 'Al Olaya', 'Riyadh', '12345', 'SA', '5678'),
                '0111111111',
                'seller@example.com',
            ),
            buyer: new EInvoiceParty(
                'customer',
                'E-Invoice Buyer',
                '300111111111113',
                null,
                new EInvoiceAddress(null, null, 'Buyer Street', 'Al Balad', 'Jeddah', '22222', 'SA', null),
                null,
                null,
            ),
            lines: [new EInvoiceLine(
                'خدمة فوترة',
                'خدمة فوترة',
                '1.000',
                '100.00',
                '0.00',
                '100.00',
                ElectronicTaxClassification::Standard,
                '15.00',
                '15.00',
                '115.00',
                null,
            )],
            tax: new EInvoiceTax(ElectronicTaxClassification::Standard, '15.00', '15.00', 'exclusive', null),
            totals: new EInvoiceTotals('100.00', '0.00', '100.00', '15.00', '115.00', '0.00', '115.00'),
            originalDocument: null,
            businessStatus: 'issued',
            paymentStatus: 'unpaid',
            complianceStatus: ComplianceStatus::Ready,
            payment: [],
            sourceMetadata: [],
        );
    }

    private function assertGeneratedXmlHasNoXmlCoreAttributes(string $xml): void
    {
        $document = new \DOMDocument;
        $document->loadXML($xml);
        $root = $document->documentElement;
        $this->assertNotNull($root);
        $stack = [$root];
        while ($stack !== []) {
            $element = array_pop($stack);
            if (! $element instanceof \DOMElement) {
                continue;
            }
            if ($element->hasAttributes()) {
                foreach ($element->attributes as $attribute) {
                    $this->assertNotSame('http://www.w3.org/XML/1998/namespace', $attribute->namespaceURI);
                    $this->assertDoesNotMatchRegularExpression('/^xml:(id|base|lang|space)$/', $attribute->nodeName);
                }
            }
            foreach ($element->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $stack[] = $child;
                }
            }
        }
    }
}
