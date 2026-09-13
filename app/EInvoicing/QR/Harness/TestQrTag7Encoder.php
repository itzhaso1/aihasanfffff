<?php

namespace App\EInvoicing\QR\Harness;

use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\Harness\SignatureArtifact;
use App\EInvoicing\Security\Harness\TestDerSignatureEncoding;
use App\EInvoicing\Security\Harness\TestP1363SignatureEncoding;

/**
 * TEST ONLY. Maps a signature artifact onto QR Tag 7.
 * Performs no ECDSA, hashing, or key generation.
 */
final class TestQrTag7Encoder
{
    public function field(SignatureArtifact $artifact): QrField
    {
        return match ($artifact->encodingId) {
            TestDerSignatureEncoding::ID => QrField::of(
                QrTag::ECDSA_SIGNATURE,
                base64_encode($artifact->bytes),
            ),
            TestP1363SignatureEncoding::ID => QrField::binary(
                QrTag::ECDSA_SIGNATURE,
                $artifact->bytes,
            ),
            default => throw new \InvalidArgumentException(
                'Unknown test signature encoding for Tag 7: '.$artifact->encodingId,
            ),
        };
    }
}
