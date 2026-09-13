<?php

namespace Tests\Unit\EInvoicing\Security;

use App\Services\EInvoicing\Security\InvoiceHashService;
use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * Independent hash vector: expected Base64 was produced with xmllint --c14n11
 * and openssl dgst -sha256 -binary | openssl base64, not InvoiceHashService.
 *
 * INPUT XML (no UBLExtensions / Signature / QR; ICV + PIH present):
 * Canonical XML is C14N 1.1 / 1.0 identical for this document (no xml:* attrs).
 * SHA-256 binary → Base64 = UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=
 */
class InvoiceHashOfficialVectorTest extends TestCase
{
    /**
     * Independently verified with:
     * xmllint --c14n11 vector.xml | openssl dgst -sha256 -binary | openssl base64 -A
     * xmllint --c14n   vector.xml | openssl dgst -sha256 -binary | openssl base64 -A
     */
    private const EXPECTED_HASH = 'UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=';

    public function test_invoice_hash_matches_independent_xmllint_and_openssl_vector(): void
    {
        $xml = $this->inputXml();
        $independentCanonical = $this->independentC14n($xml);
        $independentHash = base64_encode(hash('sha256', $independentCanonical, true));

        $this->assertSame(self::EXPECTED_HASH, $independentHash);
        $this->assertSame(self::EXPECTED_HASH, (new InvoiceHashService)->hash($xml)->value());
        $this->assertStringNotContainsString('<?xml', $independentCanonical);
        $this->assertStringContainsString('<cbc:ID>ICV</cbc:ID>', $independentCanonical);
        $this->assertStringContainsString('<cbc:UUID>1</cbc:UUID>', $independentCanonical);
        $this->assertStringContainsString('<cbc:ID>PIH</cbc:ID>', $independentCanonical);
        $this->assertStringContainsString('NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==', $independentCanonical);
        $this->assertStringNotContainsString('UBLExtensions', $independentCanonical);
        $this->assertStringNotContainsString('Signature', $independentCanonical);
        $this->assertStringNotContainsString('>QR<', $independentCanonical);
    }

    private function inputXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:ID>INV-VECTOR</cbc:ID>'
            .'<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>1</cbc:UUID></cac:AdditionalDocumentReference>'
            .'<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment>'
            .'<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==</cbc:EmbeddedDocumentBinaryObject>'
            .'</cac:Attachment></cac:AdditionalDocumentReference>'
            .'<cac:AccountingSupplierParty><cac:Party><cac:PartyLegalEntity>'
            .'<cbc:RegistrationName>Seller</cbc:RegistrationName>'
            .'</cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty>'
            .'</Invoice>';
    }

    /**
     * Spec steps without InvoiceHashService: parse, C14N inclusive without comments, SHA-256 later.
     */
    private function independentC14n(string $xml): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $this->assertTrue($loaded);
        $canonical = $document->C14N(false, false);
        $this->assertNotFalse($canonical);

        return $canonical;
    }
}
