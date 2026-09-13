<?php

namespace Tests\Unit\EInvoicing\QR\Harness;

use App\EInvoicing\QR\Harness\TestQrTag6Encoder;
use App\EInvoicing\QR\Harness\TestQrTag6Representation;
use App\EInvoicing\QR\QrEncoder;
use App\EInvoicing\QR\QrPayloadProfile;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\InvoiceHash;
use App\Services\EInvoicing\QR\EInvoiceQrService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class TestQrTag6RepresentationTest extends TestCase
{
    private const HASH = 'UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4=';

    #[Test]
    public function raw_and_base64_tag_6_candidates_consume_the_same_phase7_hash(): void
    {
        $hash = InvoiceHash::fromString(self::HASH);
        $encoder = new TestQrTag6Encoder;
        $raw = $encoder->field($hash, TestQrTag6Representation::RawSha256);
        $text = $encoder->field($hash, TestQrTag6Representation::Base64Text);

        $this->assertSame(QrTag::INVOICE_HASH, $raw->tag->value());
        $this->assertSame(QrTag::INVOICE_HASH, $text->tag->value());
        $this->assertSame(32, $raw->byteLength());
        $this->assertSame($hash->binary(), $raw->value);
        $this->assertSame($hash->value(), $text->value);
        $this->assertSame(strlen($hash->value()), $text->byteLength());
        $this->assertNotSame($raw->value, $text->value);
        $this->assertSame($hash->value(), self::HASH);
    }

    #[Test]
    public function tag_6_candidates_do_not_rehash_and_are_not_the_phase8_default_switch(): void
    {
        $hash = InvoiceHash::fromString(self::HASH);
        $rawTlv = (new QrEncoder)->encode(
            [(new TestQrTag6Encoder)->field($hash, TestQrTag6Representation::RawSha256)],
            QrPayloadProfile::Phase8Unsigned,
        );
        $textTlv = (new QrEncoder)->encode(
            [(new TestQrTag6Encoder)->field($hash, TestQrTag6Representation::Base64Text)],
            QrPayloadProfile::Phase8Unsigned,
        );

        $this->assertNotSame($rawTlv->tlvBytes, $textTlv->tlvBytes);
        $this->assertSame(chr(6).chr(32).$hash->binary(), $rawTlv->tlvBytes);
        $this->assertSame(chr(6).chr(strlen($hash->value())).$hash->value(), $textTlv->tlvBytes);

        $generate = (new ReflectionClass(EInvoiceQrService::class))->getMethod('generate');
        $source = (string) file_get_contents((new ReflectionClass(EInvoiceQrService::class))->getFileName());
        $this->assertStringContainsString('$invoiceHash->value()', $source);
        $this->assertStringNotContainsString('TestQrTag6Representation::RawSha256', $source);
        $this->assertSame(1, substr_count($source, 'QrTag::INVOICE_HASH'));
        unset($generate);
    }
}
