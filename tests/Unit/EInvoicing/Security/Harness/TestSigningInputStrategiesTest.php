<?php

namespace Tests\Unit\EInvoicing\Security\Harness;

use App\EInvoicing\Security\Harness\CryptographicProfileGuard;
use App\EInvoicing\Security\Harness\TestCryptographicProfile;
use App\EInvoicing\Security\Harness\TestInvoiceHashSigningInput;
use App\EInvoicing\Security\Harness\TestProfileEcdsaSigner;
use App\EInvoicing\Security\Harness\TestSignedInfoSigningInput;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\X509CertificateParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TestSigningInputStrategiesTest extends TestCase
{
    #[Test]
    public function invoice_hash_and_signed_info_strategies_are_distinguishable_and_test_only(): void
    {
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $invoiceHash = new TestInvoiceHashSigningInput;
        $signedInfo = new TestSignedInfoSigningInput;

        $this->assertSame('test.invoice_hash', $invoiceHash->id());
        $this->assertSame('test.signed_info', $signedInfo->id());
        $this->assertSame($hash->binary(), $invoiceHash->payload($hash));
        $this->assertNotSame($hash->binary(), $signedInfo->payload($hash));
        $this->assertStringContainsString(TestSignedInfoSigningInput::MARKER, $signedInfo->payload($hash));
        $this->assertStringContainsString($hash->value(), $signedInfo->payload($hash));
        $this->assertStringNotContainsString('production', $invoiceHash->id());
        $this->assertStringNotContainsString('production', $signedInfo->id());
    }

    #[Test]
    public function both_strategies_sign_without_relabeling_as_production_zatca(): void
    {
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $certificate = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $key = $this->pem('test-only-egs-a.key.pem');

        $hashSigner = new TestProfileEcdsaSigner(
            $key,
            TestCryptographicProfile::invoiceHashDer(),
            new CryptographicProfileGuard(treatAsProduction: false),
        );
        $signedInfoSigner = new TestProfileEcdsaSigner(
            $key,
            TestCryptographicProfile::signedInfoDer(),
            new CryptographicProfileGuard(treatAsProduction: false),
        );

        $hashArtifact = $hashSigner->signCertificate($hash, $certificate);
        $signedInfoArtifact = $signedInfoSigner->signCertificate($hash, $certificate);

        $this->assertSame('test.invoice_hash', $hashArtifact->signature->signingInputId);
        $this->assertSame('test.signed_info', $signedInfoArtifact->signature->signingInputId);
        $this->assertNotSame($hashArtifact->signature->bytes, $signedInfoArtifact->signature->bytes);
        $this->assertTrue($hashSigner->verify($hash, $hashArtifact->signature, $certificate->publicCertificatePem));
        $this->assertTrue($signedInfoSigner->verify($hash, $signedInfoArtifact->signature, $certificate->publicCertificatePem));
        $this->assertFalse($hashSigner->verify($hash, $signedInfoArtifact->signature, $certificate->publicCertificatePem));
        $this->assertFalse($hashSigner->isProductionIdentity());
        $this->assertFalse($signedInfoSigner->isProductionIdentity());
        $this->assertTrue($hashSigner->isTestOnly());
        $this->assertSame($hash->value(), $hashArtifact->signature->invoiceHash->value());
        $this->assertSame($hash->value(), $signedInfoArtifact->signature->invoiceHash->value());
    }

    #[Test]
    public function signed_info_payload_changes_when_invoice_hash_changes_but_does_not_recompute_it(): void
    {
        $first = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $second = InvoiceHash::fromBinary(str_repeat("\x02", 32));
        $strategy = new TestSignedInfoSigningInput;

        $this->assertNotSame($strategy->payload($first), $strategy->payload($second));
        $this->assertSame($first->value(), $first->value());
        $this->assertStringContainsString($first->value(), $strategy->payload($first));
        $this->assertStringNotContainsString($second->value(), $strategy->payload($first));
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/EInvoicing/'.$name);
    }
}
