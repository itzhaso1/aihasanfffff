<?php

namespace Tests\Unit\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\CertificateException;
use App\EInvoicing\Security\X509CertificateParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class X509CertificateParserTest extends TestCase
{
    #[Test]
    public function it_parses_subject_issuer_serial_validity_fingerprint_and_public_key(): void
    {
        $parsed = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), testFixture: true);

        $this->assertStringContainsString('HASEM-TEST-ONLY-A', $parsed->subject);
        $this->assertStringContainsString('HASEM-TEST-ONLY-A', $parsed->issuer);
        $this->assertNotSame('', $parsed->serialNumber);
        $this->assertNotSame('', $parsed->notBefore);
        $this->assertNotSame('', $parsed->notAfter);
        $this->assertSame(
            'f71b5e870365a5c96569961c079a63e8469459b8cefa6931cdea3c907ca717fc',
            $parsed->fingerprint->value(),
        );
        $this->assertSame('EC', $parsed->publicKey->algorithm);
        $this->assertSame(256, $parsed->publicKey->bitLength);
        $this->assertNotSame('', $parsed->publicKey->spkiDer);
        $this->assertTrue($parsed->testFixture);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $parsed->publicCertificatePem);
        $this->assertStringNotContainsString('PRIVATE KEY', $parsed->publicCertificatePem);
    }

    #[Test]
    public function it_rejects_malformed_certificates(): void
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('malformed');

        (new X509CertificateParser)->parsePem("-----BEGIN CERTIFICATE-----\nnot-a-cert\n-----END CERTIFICATE-----");
    }

    #[Test]
    public function it_rejects_private_key_material(): void
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessage('Private key');

        (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.key.pem'));
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/EInvoicing/'.$name);
    }
}
