<?php

namespace Tests\Unit\EInvoicing\Security\Harness;

use App\EInvoicing\Security\Exceptions\CertificateException;
use App\EInvoicing\Security\Harness\CertificateChain;
use App\EInvoicing\Security\X509CertificateParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CertificateChainHarnessTest extends TestCase
{
    #[Test]
    public function test_fixture_chain_exposes_metadata_without_private_keys(): void
    {
        $parser = new X509CertificateParser;
        $leaf = $parser->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $other = $parser->parsePem($this->pem('test-only-egs-b.crt.pem'), true);
        $chain = new CertificateChain([$leaf, $other]);

        $this->assertSame($leaf->fingerprint->value(), $chain->leaf()->fingerprint->value());
        $this->assertCount(2, $chain->subjects());
        $this->assertNotSame('', $leaf->serialNumber);
        $this->assertNotSame('', $leaf->notBefore);
        $this->assertNotSame('', $leaf->notAfter);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $leaf->publicCertificatePem);
        $this->assertStringNotContainsString('PRIVATE KEY', $leaf->publicCertificatePem);
        $this->assertTrue($leaf->testFixture);
        $this->assertSame('test.certificate_chain', $chain->profileIdentifier);
    }

    #[Test]
    public function non_test_certificates_cannot_form_a_harness_chain(): void
    {
        $leaf = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), false);
        $this->expectException(CertificateException::class);
        new CertificateChain([$leaf]);
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/EInvoicing/'.$name);
    }
}
