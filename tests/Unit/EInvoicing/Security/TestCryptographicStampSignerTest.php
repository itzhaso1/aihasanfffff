<?php

namespace Tests\Unit\EInvoicing\Security;

use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\SigningInput;
use App\EInvoicing\Security\StampStatus;
use App\EInvoicing\Security\X509CertificateParser;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Services\EInvoicing\Security\DeferredCryptographicStampSigner;
use App\Services\EInvoicing\Security\TestCryptographicStampSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TestCryptographicStampSignerTest extends TestCase
{
    #[Test]
    public function independent_openssl_verifies_the_test_signature(): void
    {
        $certificate = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $signer = new TestCryptographicStampSigner(
            $this->pem('test-only-egs-a.key.pem'),
            $certificate->fingerprint->value(),
        );

        $stamp = $signer->sign(new SigningInput(
            invoiceHash: $hash,
            workspaceId: 1,
            egsUnitId: 1,
            eInvoiceDocumentId: 1,
            documentIdentity: IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE.':1:snapshot:1',
            certificate: $certificate,
        ));

        $der = base64_decode($stamp->signatureDerBase64, true);
        $this->assertNotFalse($der);
        $this->assertSame(StampStatus::TestSigned, $stamp->status);
        $this->assertFalse($stamp->isProductionIdentity());
        $this->assertFalse($signer->isProductionIdentity());
        $this->assertTrue($signer->isTestOnly());
        $this->assertSame($hash->value(), $stamp->invoiceHash->value());
        $this->assertTrue($signer->verify($hash->binary(), $der, $certificate->publicCertificatePem));

        $independent = openssl_verify(
            $hash->binary(),
            $der,
            $certificate->publicCertificatePem,
            OPENSSL_ALGO_SHA256,
        );
        $this->assertSame(1, $independent);
    }

    #[Test]
    public function mutating_the_signing_input_fails_verification(): void
    {
        $certificate = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $signer = new TestCryptographicStampSigner($this->pem('test-only-egs-a.key.pem'));
        $stamp = $signer->sign(new SigningInput(
            invoiceHash: $hash,
            workspaceId: 1,
            egsUnitId: 1,
            eInvoiceDocumentId: 1,
            documentIdentity: 'doc',
            certificate: $certificate,
        ));
        $der = base64_decode($stamp->signatureDerBase64, true);
        $mutated = $hash->binary();
        $mutated[0] = chr(ord($mutated[0]) ^ 0xFF);

        $this->assertSame(0, openssl_verify($mutated, $der, $certificate->publicCertificatePem, OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function mutating_the_public_key_fails_verification(): void
    {
        $certificate = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $other = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-b.crt.pem'), true);
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $signer = new TestCryptographicStampSigner($this->pem('test-only-egs-a.key.pem'));
        $stamp = $signer->sign(new SigningInput(
            invoiceHash: $hash,
            workspaceId: 1,
            egsUnitId: 1,
            eInvoiceDocumentId: 1,
            documentIdentity: 'doc',
            certificate: $certificate,
        ));
        $der = base64_decode($stamp->signatureDerBase64, true);

        $this->assertSame(0, openssl_verify($hash->binary(), $der, $other->publicCertificatePem, OPENSSL_ALGO_SHA256));
    }

    #[Test]
    public function deferred_signer_is_not_a_production_identity_and_refuses_to_stamp(): void
    {
        $deferred = new DeferredCryptographicStampSigner;
        $this->assertFalse($deferred->isProductionIdentity());
        $this->assertFalse($deferred->isTestOnly());

        $certificate = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $this->expectExceptionMessage('deferred');
        $deferred->sign(new SigningInput(
            invoiceHash: InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4='),
            workspaceId: 1,
            egsUnitId: 1,
            eInvoiceDocumentId: 1,
            documentIdentity: 'doc',
            certificate: $certificate,
        ));
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/EInvoicing/'.$name);
    }
}
