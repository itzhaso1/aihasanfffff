<?php

namespace Tests\Unit\EInvoicing\QR\Harness;

use App\EInvoicing\QR\Harness\TestOnlyExternalCaArtifactProvider;
use App\EInvoicing\QR\Harness\TestQrCryptographicAssembler;
use App\EInvoicing\QR\Harness\TestQrTag6Representation;
use App\EInvoicing\QR\Harness\TestQrTag7Encoder;
use App\EInvoicing\QR\Harness\TestQrTag8Encoder;
use App\EInvoicing\QR\Harness\TestQrTag8Representation;
use App\EInvoicing\QR\QrEncoder;
use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\Harness\CryptographicProfileGuard;
use App\EInvoicing\Security\Harness\TestCryptographicArtifact;
use App\EInvoicing\Security\Harness\TestCryptographicProfile;
use App\EInvoicing\Security\Harness\TestProfileEcdsaSigner;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\X509CertificateParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class TestQrTagAdaptersTest extends TestCase
{
    #[Test]
    public function tag_7_consumes_signature_artifact_without_signing(): void
    {
        $artifact = $this->artifact();
        $field = (new TestQrTag7Encoder)->field($artifact->signature);
        $this->assertSame(QrTag::ECDSA_SIGNATURE, $field->tag->value());
        $this->assertSame(base64_encode($artifact->signature->bytes), $field->value);

        $encoderSource = (string) file_get_contents((new ReflectionClass(QrEncoder::class))->getFileName());
        $tag7Source = (string) file_get_contents((new ReflectionClass(TestQrTag7Encoder::class))->getFileName());
        $this->assertStringNotContainsString('openssl_sign', $encoderSource);
        $this->assertStringNotContainsString('openssl_sign', $tag7Source);
        $this->assertStringNotContainsString('InvoiceHashService', $tag7Source);
    }

    #[Test]
    public function tag_8_consumes_public_key_artifact_as_spki_or_uncompressed_point(): void
    {
        $artifact = $this->artifact();
        $encoder = new TestQrTag8Encoder;
        $spki = $encoder->field($artifact->publicKey, TestQrTag8Representation::SpkiDer);
        $point = $encoder->field($artifact->publicKey, TestQrTag8Representation::UncompressedPoint);

        $this->assertSame($artifact->publicKey->spkiDer, $spki->value);
        $this->assertSame(65, strlen($point->value));
        $this->assertSame("\x04", $point->value[0]);
        $this->assertNotSame($spki->value, $point->value);
        $this->assertStringEndsWith($point->value, $spki->value);

        $tag8Source = (string) file_get_contents((new ReflectionClass(TestQrTag8Encoder::class))->getFileName());
        $this->assertStringNotContainsString('openssl_sign', $tag8Source);
        $this->assertStringNotContainsString('openssl_x509', $tag8Source);
    }

    #[Test]
    public function tag_9_consumes_an_explicit_external_test_fixture_not_a_zatca_ca_signature(): void
    {
        $hex = '3046022100ee61d3eb283ce63b50196a7733bb4f4fb264dbececbd51c6b376d4e59ed813af022100fad1e6d06a662362f75e6e716335fc785f8768a7b2ec101142352b0b63420569';
        $provider = TestOnlyExternalCaArtifactProvider::fromHex($hex);
        $artifact = $provider->artifact();

        $this->assertSame(TestOnlyExternalCaArtifactProvider::MARKER, $artifact->marker);
        $this->assertTrue($artifact->syntheticTestFixture);
        $this->assertSame(hex2bin($hex), $artifact->bytes);
        $this->assertStringNotContainsString('ZATCA Technical CA', $artifact->marker);
    }

    #[Test]
    public function assembler_uses_qr_encoder_for_tlv_and_does_not_allocate_crypto(): void
    {
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $artifact = $this->artifact();
        $tag9 = TestOnlyExternalCaArtifactProvider::fromHex(
            '3046022100ee61d3eb283ce63b50196a7733bb4f4fb264dbececbd51c6b376d4e59ed813af022100fad1e6d06a662362f75e6e716335fc785f8768a7b2ec101142352b0b63420569',
        )->artifact();

        $payload = (new TestQrCryptographicAssembler)->assemble(
            [
                QrField::of(QrTag::SELLER_NAME, 'Issued Co'),
                QrField::of(QrTag::SELLER_VAT, '310000000000003'),
                QrField::of(QrTag::TIMESTAMP, '2026-09-01T10:15:30'),
                QrField::of(QrTag::TOTAL_WITH_VAT, '115.00'),
                QrField::of(QrTag::VAT_TOTAL, '15.00'),
            ],
            $hash,
            TestQrTag6Representation::Base64Text,
            $artifact,
            TestQrTag8Representation::SpkiDer,
            $tag9,
        );

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $payload->tagOrder());
        $this->assertSame($hash->value(), $payload->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame(base64_encode($artifact->signature->bytes), $payload->valueForTag(QrTag::ECDSA_SIGNATURE));
        $this->assertSame($artifact->publicKey->spkiDer, $payload->valueForTag(QrTag::ECDSA_PUBLIC_KEY));
        $this->assertSame($tag9->bytes, $payload->valueForTag(QrTag::ZATCA_CA_SIGNATURE));

        $source = (string) file_get_contents((new ReflectionClass(TestQrCryptographicAssembler::class))->getFileName());
        $this->assertStringNotContainsString('openssl_sign', $source);
        $this->assertStringNotContainsString('IcvAllocator', $source);
        $this->assertStringNotContainsString('InvoiceHashService', $source);
    }

    private function artifact(): TestCryptographicArtifact
    {
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $certificate = (new X509CertificateParser)->parsePem($this->pem('test-only-egs-a.crt.pem'), true);
        $signer = new TestProfileEcdsaSigner(
            $this->pem('test-only-egs-a.key.pem'),
            TestCryptographicProfile::invoiceHashDer(),
            new CryptographicProfileGuard(treatAsProduction: false),
        );

        return $signer->signCertificate($hash, $certificate);
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/EInvoicing/'.$name);
    }
}
