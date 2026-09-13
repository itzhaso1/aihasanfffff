<?php

namespace Tests\Unit\EInvoicing\QR;

use App\EInvoicing\QR\CryptographicQrFields;
use App\EInvoicing\QR\QrEncoder;
use App\EInvoicing\QR\QrEncodingException;
use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrFieldSet;
use App\EInvoicing\QR\QrPayloadProfile;
use App\EInvoicing\QR\QrTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class QrEncoderTest extends TestCase
{
    #[Test]
    public function it_encodes_basic_tlv_bytes_and_independent_base64(): void
    {
        $payload = (new QrEncoder)->encode([
            QrField::of(QrTag::SELLER_NAME, 'Seller'),
            QrField::of(QrTag::SELLER_VAT, 'VAT123'),
        ]);

        $this->assertSame(
            hex2bin('010653656c6c65720206564154313233'),
            $payload->tlvBytes,
        );
        $this->assertSame('AQZTZWxsZXICBlZBVDEyMw==', $payload->base64);
        $this->assertSame('AQZTZWxsZXICBlZBVDEyMw==', base64_encode($payload->tlvBytes));
    }

    #[Test]
    public function it_uses_utf8_byte_length_not_character_count(): void
    {
        $payload = (new QrEncoder)->encode([
            QrField::of(QrTag::SELLER_NAME, 'شركة'),
        ]);

        $this->assertSame(4, mb_strlen('شركة', 'UTF-8'));
        $this->assertSame(8, strlen('شركة'));
        $this->assertSame(hex2bin('0108d8b4d8b1d983d8a9'), $payload->tlvBytes);
        $this->assertSame(1, ord($payload->tlvBytes[0]));
        $this->assertSame(8, ord($payload->tlvBytes[1]));
    }

    #[Test]
    public function official_field_set_reorders_deliberately_unsorted_input(): void
    {
        $fields = QrFieldSet::inOfficialOrder([
            QrField::of(QrTag::VAT_TOTAL, '15.00'),
            QrField::of(QrTag::SELLER_VAT, 'VAT123'),
            QrField::of(QrTag::INVOICE_HASH, 'UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4='),
            QrField::of(QrTag::SELLER_NAME, 'Seller'),
            QrField::of(QrTag::TOTAL_WITH_VAT, '115.00'),
            QrField::of(QrTag::TIMESTAMP, '2026-09-01T10:15:30'),
        ]);

        $this->assertSame([1, 2, 3, 4, 5, 6], array_map(
            static fn (QrField $field): int => $field->tag->value(),
            $fields,
        ));

        $payload = (new QrEncoder)->encode($fields);
        $this->assertSame([1, 2, 3, 4, 5, 6], $payload->tagOrder());
    }

    #[Test]
    public function encoder_preserves_explicit_caller_order_without_hidden_sort(): void
    {
        $payload = (new QrEncoder)->encode([
            QrField::of(QrTag::VAT_TOTAL, '15.00'),
            QrField::of(QrTag::SELLER_NAME, 'Seller'),
        ]);

        $this->assertSame([5, 1], $payload->tagOrder());
    }

    #[Test]
    public function independent_full_vector_matches_stored_base64_literal(): void
    {
        $raw = hex2bin(
            '010653656c6c6572'
            .'0206564154313233'
            .'0313323032362d30392d30315431303a31353a3330'
            .'04063131352e3030'
            .'050531352e3030'
            .'062c5551547a4a466d664a476f67392f6a4b3357693559335654797a4a7a71445246327a7038444232783769343d'
        );
        $this->assertNotFalse($raw);

        $expectedBase64 = 'AQZTZWxsZXICBlZBVDEyMwMTMjAyNi0wOS0wMVQxMDoxNTozMAQGMTE1LjAwBQUxNS4wMAYsVVFUekpGbWZKR29nOS9qSzNXaTVZM1ZUeXpKenFEUkYyenA4REIyeDdpND0=';
        $this->assertSame($expectedBase64, base64_encode($raw));

        $payload = (new QrEncoder)->encode([
            QrField::of(QrTag::SELLER_NAME, 'Seller'),
            QrField::of(QrTag::SELLER_VAT, 'VAT123'),
            QrField::of(QrTag::TIMESTAMP, '2026-09-01T10:15:30'),
            QrField::of(QrTag::TOTAL_WITH_VAT, '115.00'),
            QrField::of(QrTag::VAT_TOTAL, '15.00'),
            QrField::of(QrTag::INVOICE_HASH, 'UQTzJFmfJGog9/jK3Wi5Y3VTyzJzqDRF2zp8DB2x7i4='),
        ]);

        $this->assertSame($raw, $payload->tlvBytes);
        $this->assertSame($expectedBase64, $payload->base64);
        $this->assertSame(QrPayloadProfile::Phase8Unsigned, $payload->profile);
    }

    #[Test]
    public function future_cryptographic_fields_append_in_official_order(): void
    {
        $crypto = new CryptographicQrFields(
            ecdsaSignature: 'SIG7',
            ecdsaPublicKey: 'KEY8',
            zatcaCaSignature: 'CA9',
        );

        $fields = QrFieldSet::inOfficialOrder([
            QrField::of(QrTag::SELLER_NAME, 'Seller'),
            QrField::of(QrTag::SELLER_VAT, 'VAT123'),
            QrField::of(QrTag::TIMESTAMP, '2026-09-01T10:15:30'),
            QrField::of(QrTag::TOTAL_WITH_VAT, '115.00'),
            QrField::of(QrTag::VAT_TOTAL, '15.00'),
            QrField::of(QrTag::INVOICE_HASH, 'hash'),
            QrField::of(QrTag::ECDSA_SIGNATURE, $crypto->ecdsaSignature),
            QrField::of(QrTag::ECDSA_PUBLIC_KEY, $crypto->ecdsaPublicKey),
            QrField::of(QrTag::ZATCA_CA_SIGNATURE, $crypto->zatcaCaSignature),
        ]);

        $payload = (new QrEncoder)->encode($fields, QrPayloadProfile::WithCryptographicFields);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $payload->tagOrder());
        $this->assertSame('SIG7', $payload->valueForTag(QrTag::ECDSA_SIGNATURE));
        $this->assertSame('KEY8', $payload->valueForTag(QrTag::ECDSA_PUBLIC_KEY));
        $this->assertSame('CA9', $payload->valueForTag(QrTag::ZATCA_CA_SIGNATURE));
        $this->assertSame(QrPayloadProfile::WithCryptographicFields, $payload->profile);
        $this->assertTrue($payload->includesCryptographicTags());
    }

    #[Test]
    public function empty_value_is_rejected(): void
    {
        $this->expectException(QrEncodingException::class);

        QrField::of(QrTag::SELLER_NAME, '');
    }

    #[Test]
    public function unsupported_tag_is_rejected(): void
    {
        $this->expectException(QrEncodingException::class);

        QrTag::fromInt(10);
    }
}
