<?php

namespace Tests\Unit\EInvoicing\QR;

use App\EInvoicing\QR\CryptographicStampQrMapper;
use App\EInvoicing\QR\QrEncoder;
use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrPayloadProfile;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\CertificateFingerprint;
use App\EInvoicing\Security\CryptographicStamp;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\SigningAlgorithm;
use App\EInvoicing\Security\StampStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CryptographicStampQrMapperTest extends TestCase
{
    #[Test]
    public function tag_7_and_8_use_official_representations_and_tag_9_is_absent(): void
    {
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $signature = 'MEUCIQD5zxyXOB7NvWf62rVEZAYU71jpy9HEEnZ0q9O96wrL6QIgQJzCGHbw6YBHLYVdO1wnUhBgKm8jMTyvck9M+rP9xYY=';
        $spki = hex2bin('3056301006072a8648ce3d020106052b8104000a0342000461830ca0e68560084c3bfb2d7a8b5f6726afafaa75d524a5c2c2bd6b39ac2d8edbd5bf852e1a8c02b841d9da8729ba31a8a35fbe428378f869aa3ba2e61727d109');
        $this->assertNotFalse($spki);

        $stamp = new CryptographicStamp(
            status: StampStatus::TestSigned,
            invoiceHash: $hash,
            signatureDerBase64: $signature,
            publicKeySpkiDer: $spki,
            signatureAlgorithm: SigningAlgorithm::TEST_PROFILE,
            curve: SigningAlgorithm::TEST_CURVE,
            certificateFingerprint: CertificateFingerprint::fromSha256Hex(str_repeat('ab', 32)),
            certificateId: 1,
            signedInputIdentifier: SigningAlgorithm::SIGNED_INPUT,
            productionIdentity: false,
        );

        $fields = (new CryptographicStampQrMapper)->fields($stamp);
        $this->assertSame($signature, $fields->ecdsaSignature);
        $this->assertSame($spki, $fields->ecdsaPublicKey);
        $this->assertNull($fields->zatcaCaSignature);

        $independent = chr(7).chr(strlen($signature)).$signature.chr(8).chr(strlen($spki)).$spki;
        $encoded = (new QrEncoder)->encode([
            QrField::of(QrTag::ECDSA_SIGNATURE, $signature),
            QrField::binary(QrTag::ECDSA_PUBLIC_KEY, $spki),
        ], QrPayloadProfile::WithCryptographicFields);

        $this->assertSame($independent, $encoded->tlvBytes);
        $this->assertSame(base64_encode($independent), $encoded->base64);
        $this->assertSame([7, 8], $encoded->tagOrder());
        $this->assertFalse($encoded->hasTag(QrTag::ZATCA_CA_SIGNATURE));
    }

    #[Test]
    public function tag_9_is_included_only_when_an_external_artifact_is_supplied(): void
    {
        $hash = InvoiceHash::fromString('UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=');
        $stamp = new CryptographicStamp(
            status: StampStatus::TestSigned,
            invoiceHash: $hash,
            signatureDerBase64: 'MEUC',
            publicKeySpkiDer: 'SPKI',
            signatureAlgorithm: SigningAlgorithm::TEST_PROFILE,
            curve: SigningAlgorithm::TEST_CURVE,
            certificateFingerprint: CertificateFingerprint::fromSha256Hex(str_repeat('cd', 32)),
            certificateId: 1,
            signedInputIdentifier: SigningAlgorithm::SIGNED_INPUT,
            productionIdentity: false,
        );

        $ca = hex2bin('3046022100ee61d3eb283ce63b50196a7733bb4f4fb264dbececbd51c6b376d4e59ed813af022100fad1e6d06a662362f75e6e716335fc785f8768a7b2ec101142352b0b63420569');
        $this->assertNotFalse($ca);
        $fields = (new CryptographicStampQrMapper)->fields($stamp, $ca);
        $this->assertSame($ca, $fields->zatcaCaSignature);

        $this->assertNull((new CryptographicStampQrMapper)->fields($stamp)->zatcaCaSignature);
    }
}
