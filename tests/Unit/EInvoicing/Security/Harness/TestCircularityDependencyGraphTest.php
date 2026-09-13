<?php

namespace Tests\Unit\EInvoicing\Security\Harness;

use App\EInvoicing\Security\Harness\CryptographicProfileGuard;
use App\EInvoicing\Security\Harness\TestCryptographicProfile;
use App\EInvoicing\Security\Harness\TestProfileEcdsaSigner;
use App\EInvoicing\Security\Harness\TestSignedInfoSigningInput;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\Pih;
use App\EInvoicing\Security\X509CertificateParser;
use App\Services\EInvoicing\Security\InvoiceHashService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TestCircularityDependencyGraphTest extends TestCase
{
    #[Test]
    public function hash_does_not_depend_on_signature_value_or_qr_and_signature_does_not_change_hash(): void
    {
        $service = new InvoiceHashService;
        $base = $this->invoiceXml();
        $withSignatureAndQr = str_replace(
            '</Invoice>',
            '<UBLExtensions xmlns="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">'
            .'<UBLExtension><SignatureValue>MEUCIQDnot-a-real-signature</SignatureValue></UBLExtension></UBLExtensions>'
            .'<cac:AdditionalDocumentReference xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>QR</cbc:ID><cbc:EmbeddedDocumentBinaryObject>cXItYnl0ZXM=</cbc:EmbeddedDocumentBinaryObject></cac:AdditionalDocumentReference>'
            .'<Signature xmlns="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2">MEUCIQDnot-a-real-signature</Signature>'
            .'</Invoice>',
            $base,
        );

        $hash = $service->hash($base);
        $this->assertTrue($hash->equals($service->hash($withSignatureAndQr)));

        $certificate = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $signer = new TestProfileEcdsaSigner(
            $this->pem('test-only-egs-a.key.pem'),
            TestCryptographicProfile::signedInfoDer(),
            new CryptographicProfileGuard(treatAsProduction: false),
        );
        $artifact = $signer->signCertificate($hash, $certificate);

        $this->assertSame($hash->value(), $artifact->signature->invoiceHash->value());
        $this->assertTrue($hash->equals($service->hash($base)));
        $this->assertTrue($hash->equals($service->hash($withSignatureAndQr)));
        $this->assertStringContainsString($hash->value(), (new TestSignedInfoSigningInput)->payload($hash));
        $this->assertStringNotContainsString($artifact->signature->bytes, $hash->value());
    }

    #[Test]
    public function signed_info_strategy_references_invoice_hash_without_a_circular_write(): void
    {
        $hash = InvoiceHash::fromBinary(str_repeat("\x11", 32));
        $payload = (new TestSignedInfoSigningInput)->payload($hash);
        $this->assertStringContainsString($hash->value(), $payload);
        $this->assertNotSame($hash->binary(), $payload);
        $this->assertSame(32, strlen($hash->binary()));
    }

    private function invoiceXml(): string
    {
        return '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:ID>INV-CIRC</cbc:ID>'
            .'<cbc:IssueDate>2026-09-01</cbc:IssueDate>'
            .'<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>1</cbc:UUID></cac:AdditionalDocumentReference>'
            .'<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment>'
            .'<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">'.Pih::FIRST_DOCUMENT.'</cbc:EmbeddedDocumentBinaryObject>'
            .'</cac:Attachment></cac:AdditionalDocumentReference>'
            .'<cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="SAR">115.00</cbc:PayableAmount></cac:LegalMonetaryTotal>'
            .'</Invoice>';
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/EInvoicing/'.$name);
    }
}
