<?php

namespace App\EInvoicing\QR\Harness;

use App\EInvoicing\QR\QrEncodingException;
use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\PublicKey;

/**
 * TEST ONLY. Maps a public-key artifact onto QR Tag 8.
 * Performs no signing, hashing, or certificate parsing beyond SPKI bytes already supplied.
 */
final class TestQrTag8Encoder
{
    public function field(PublicKey $publicKey, TestQrTag8Representation $representation): QrField
    {
        return match ($representation) {
            TestQrTag8Representation::SpkiDer => QrField::binary(QrTag::ECDSA_PUBLIC_KEY, $publicKey->spkiDer),
            TestQrTag8Representation::UncompressedPoint => QrField::binary(
                QrTag::ECDSA_PUBLIC_KEY,
                $this->uncompressedPoint($publicKey->spkiDer),
            ),
        };
    }

    private function uncompressedPoint(string $spkiDer): string
    {
        $marker = "\x03\x42\x00\x04";
        $position = strpos($spkiDer, $marker);
        if ($position === false) {
            throw new QrEncodingException(
                'Test Tag 8 uncompressed-point candidate requires an uncompressed EC point in SPKI.',
                field: 'tag_8',
                reason: 'unsupported_spki',
            );
        }

        $point = substr($spkiDer, $position + 3, 65);
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new QrEncodingException(
                'Test Tag 8 uncompressed point is truncated.',
                field: 'tag_8',
                reason: 'truncated_point',
            );
        }

        return $point;
    }
}
