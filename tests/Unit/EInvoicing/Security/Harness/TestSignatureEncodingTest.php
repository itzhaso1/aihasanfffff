<?php

namespace Tests\Unit\EInvoicing\Security\Harness;

use App\EInvoicing\Security\Harness\CryptographicProfileGuard;
use App\EInvoicing\Security\Harness\TestCryptographicProfile;
use App\EInvoicing\Security\Harness\TestDerSignatureEncoding;
use App\EInvoicing\Security\Harness\TestEcdsaSignatureCodec;
use App\EInvoicing\Security\Harness\TestP1363SignatureEncoding;
use App\EInvoicing\Security\Harness\TestProfileEcdsaSigner;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\X509CertificateParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TestSignatureEncodingTest extends TestCase
{
    #[Test]
    public function der_and_p1363_encodings_are_distinguishable_and_reversible(): void
    {
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $certificate = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $key = $this->pem('test-only-egs-a.key.pem');
        $derSigner = new TestProfileEcdsaSigner(
            $key,
            TestCryptographicProfile::invoiceHashDer(),
            new CryptographicProfileGuard(treatAsProduction: false),
        );
        $p1363Signer = new TestProfileEcdsaSigner(
            $key,
            TestCryptographicProfile::invoiceHashP1363(),
            new CryptographicProfileGuard(treatAsProduction: false),
        );

        $derArtifact = $derSigner->signHash($hash);
        $p1363Artifact = $p1363Signer->signHash($hash);

        $this->assertSame(TestDerSignatureEncoding::ID, $derArtifact->encodingId);
        $this->assertSame(TestP1363SignatureEncoding::ID, $p1363Artifact->encodingId);
        $this->assertSame(0x30, ord($derArtifact->bytes[0]));
        $this->assertSame(64, strlen($p1363Artifact->bytes));
        $this->assertNotSame($derArtifact->bytes, $p1363Artifact->bytes);

        $codec = new TestEcdsaSignatureCodec;
        $roundTrip = $codec->p1363ToDer($codec->derToP1363($derArtifact->bytes));
        $this->assertTrue($derSigner->verify($hash, $derArtifact, $certificate->publicCertificatePem));
        $this->assertTrue($p1363Signer->verify($hash, $p1363Artifact, $certificate->publicCertificatePem));

        $independent = openssl_verify(
            $hash->binary(),
            $roundTrip,
            $certificate->publicCertificatePem,
            OPENSSL_ALGO_SHA256,
        );
        $this->assertSame(1, $independent);
        $this->assertStringStartsWith('test.', $derArtifact->profileIdentifier);
        $this->assertStringStartsWith('test.', $p1363Artifact->profileIdentifier);
    }

    #[Test]
    public function encodings_are_parseable_without_being_a_production_qr_default(): void
    {
        $der = new TestDerSignatureEncoding;
        $p1363 = new TestP1363SignatureEncoding;
        $this->assertSame('test.der', $der->id());
        $this->assertSame('test.p1363', $p1363->id());
        $this->assertNotSame($der->id(), $p1363->id());
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/EInvoicing/'.$name);
    }
}
