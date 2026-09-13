<?php

namespace Tests\Unit\EInvoicing\Security;

use App\EInvoicing\Security\CanonicalizationMethod;
use App\EInvoicing\Security\Exceptions\InvoiceHashException;
use App\EInvoicing\Security\HashAlgorithm;
use App\EInvoicing\Security\Pih;
use App\Services\EInvoicing\Security\InvoiceHashService;
use PHPUnit\Framework\TestCase;

class InvoiceHashServiceTest extends TestCase
{
    public function test_same_xml_produces_the_same_hash(): void
    {
        $xml = $this->fixture();
        $service = new InvoiceHashService;
        $this->assertTrue($service->hash($xml)->equals($service->hash($xml)));
        $this->assertSame(HashAlgorithm::SHA_256, $service->algorithm());
        $this->assertSame(CanonicalizationMethod::C14N11, $service->canonicalizationMethod());
    }

    public function test_equivalent_formatting_produces_the_same_hash(): void
    {
        $pretty = $this->fixture();
        $compact = preg_replace('/>\s+</', '><', $pretty) ?? $pretty;
        $this->assertNotSame($pretty, $compact);

        $service = new InvoiceHashService;
        $this->assertTrue($service->hash($pretty)->equals($service->hash($compact)));
    }

    public function test_qr_signature_and_ubl_extensions_are_excluded_from_the_hash(): void
    {
        $base = $this->fixture();
        $withExcluded = str_replace(
            '</Invoice>',
            '<UBLExtensions xmlns="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"><UBLExtension/></UBLExtensions>'
            .'<cac:AdditionalDocumentReference xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>QR</cbc:ID></cac:AdditionalDocumentReference>'
            .'<Signature xmlns="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2">ignored</Signature>'
            .'</Invoice>',
            $base,
        );

        $service = new InvoiceHashService;
        $this->assertTrue($service->hash($base)->equals($service->hash($withExcluded)));
    }

    public function test_icv_and_pih_are_included_in_the_hash(): void
    {
        $service = new InvoiceHashService;
        $first = $this->fixture();
        $changedIcv = str_replace('<cbc:UUID>1</cbc:UUID>', '<cbc:UUID>2</cbc:UUID>', $first);
        $changedPih = str_replace(Pih::FIRST_DOCUMENT, base64_encode(str_repeat("\x01", 32)), $first);

        $this->assertFalse($service->hash($first)->equals($service->hash($changedIcv)));
        $this->assertFalse($service->hash($first)->equals($service->hash($changedPih)));
    }

    public function test_canonical_xml_keeps_icv_pih_and_drops_excluded_nodes(): void
    {
        $service = new InvoiceHashService;
        $canonical = $service->canonicalize($this->xmlWithExcludedAndOrdinaryReferences())->value();

        $this->assertStringContainsString('<cbc:ID>ICV</cbc:ID>', $canonical);
        $this->assertStringContainsString('<cbc:UUID>1</cbc:UUID>', $canonical);
        $this->assertStringContainsString('<cbc:ID>PIH</cbc:ID>', $canonical);
        $this->assertStringContainsString('<cbc:ID>PO</cbc:ID>', $canonical);
        $this->assertStringContainsString('PO-99', $canonical);
        $this->assertStringNotContainsString('UBLExtensions', $canonical);
        $this->assertStringNotContainsString('UBLExtension', $canonical);
        $this->assertStringNotContainsString('Signature', $canonical);
        $this->assertStringNotContainsString('>QR<', $canonical);
        $this->assertStringNotContainsString('<?xml', $canonical);
        $this->assertStringNotContainsString('<!--', $canonical);
    }

    public function test_xml_declaration_comments_and_pretty_print_do_not_change_the_digest(): void
    {
        $compact = $this->compactInvoice();
        $withDeclaration = '<?xml version="1.0" encoding="UTF-8"?>'.$compact;
        $withComment = str_replace('<cbc:ID>INV-MUT</cbc:ID>', '<cbc:ID>INV-MUT</cbc:ID><!-- ignored -->', $compact);
        $pretty = preg_replace('/></', ">\n<", $compact) ?? $compact;

        $service = new InvoiceHashService;
        $base = $service->hash($compact);
        $this->assertTrue($base->equals($service->hash($withDeclaration)));
        $this->assertTrue($base->equals($service->hash($withComment)));
        $this->assertTrue($base->equals($service->hash($pretty)));
    }

    public function test_security_relevant_mutations_change_the_hash(): void
    {
        $service = new InvoiceHashService;
        $base = $service->hash($this->compactInvoice())->value();

        $mutations = [
            'invoice number' => str_replace('INV-MUT', 'INV-OTHER', $this->compactInvoice()),
            'issue date' => str_replace('2026-09-01', '2026-09-02', $this->compactInvoice()),
            'amount' => str_replace('115.00', '200.00', $this->compactInvoice()),
            'ICV' => str_replace('<cbc:UUID>1</cbc:UUID>', '<cbc:UUID>2</cbc:UUID>', $this->compactInvoice()),
            'PIH' => str_replace(
                'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==',
                base64_encode(str_repeat("\x03", 32)),
                $this->compactInvoice(),
            ),
            'seller VAT' => str_replace('310000000000003', '399999999999993', $this->compactInvoice()),
            'line amount' => str_replace('100.00', '90.00', $this->compactInvoice()),
        ];

        foreach ($mutations as $label => $xml) {
            $this->assertNotSame($base, $service->hash($xml)->value(), $label.' must change the invoice hash');
        }
    }

    public function test_malformed_xml_is_rejected(): void
    {
        $this->expectException(InvoiceHashException::class);
        (new InvoiceHashService)->hash('<not-closed>');
    }

    public function test_xml_core_attributes_are_refused_rather_than_guessed(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xml:lang="ar">
  <ID>INV-1</ID>
</Invoice>
XML;

        $this->expectException(InvoiceHashException::class);
        (new InvoiceHashService)->hash($xml);
    }

    private function fixture(): string
    {
        $path = dirname(__DIR__, 3).'/Fixtures/EInvoicing/security/golden-invoice.xml';

        return (string) file_get_contents($path);
    }

    private function compactInvoice(): string
    {
        return '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:ID>INV-MUT</cbc:ID>'
            .'<cbc:IssueDate>2026-09-01</cbc:IssueDate>'
            .'<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>1</cbc:UUID></cac:AdditionalDocumentReference>'
            .'<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment>'
            .'<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==</cbc:EmbeddedDocumentBinaryObject>'
            .'</cac:Attachment></cac:AdditionalDocumentReference>'
            .'<cac:AccountingSupplierParty><cac:Party><cac:PartyTaxScheme>'
            .'<cbc:CompanyID>310000000000003</cbc:CompanyID></cac:PartyTaxScheme></cac:Party></cac:AccountingSupplierParty>'
            .'<cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="SAR">115.00</cbc:PayableAmount></cac:LegalMonetaryTotal>'
            .'<cac:InvoiceLine><cbc:LineExtensionAmount currencyID="SAR">100.00</cbc:LineExtensionAmount></cac:InvoiceLine>'
            .'</Invoice>';
    }

    private function xmlWithExcludedAndOrdinaryReferences(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<!-- comment must not be hashed -->'
            .'<cbc:ID>INV-MUT</cbc:ID>'
            .'<UBLExtensions xmlns="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"><UBLExtension/></UBLExtensions>'
            .'<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>1</cbc:UUID></cac:AdditionalDocumentReference>'
            .'<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment>'
            .'<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==</cbc:EmbeddedDocumentBinaryObject>'
            .'</cac:Attachment></cac:AdditionalDocumentReference>'
            .'<cac:AdditionalDocumentReference><cbc:ID>PO</cbc:ID><cbc:DocumentDescription>PO-99</cbc:DocumentDescription></cac:AdditionalDocumentReference>'
            .'<cac:AdditionalDocumentReference><cbc:ID>QR</cbc:ID></cac:AdditionalDocumentReference>'
            .'<Signature xmlns="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2">ignored</Signature>'
            .'<cac:AccountingSupplierParty><cac:Party><cac:PartyLegalEntity>'
            .'<cbc:RegistrationName>Seller</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty>'
            .'</Invoice>';
    }
}
