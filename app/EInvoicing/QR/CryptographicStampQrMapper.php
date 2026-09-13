<?php

namespace App\EInvoicing\QR;

use App\EInvoicing\Security\CryptographicStamp;
use App\EInvoicing\Security\Exceptions\CryptographicStampException;

/**
 * Maps a local TEST stamp onto QR Tags 7–8 for Phase 9 foundation tests.
 *
 * This mapper does not establish the production ZATCA Tag 7/8 encoding
 * (Phase 9A: UNRESOLVED). Tag 9 is never generated here.
 */
final class CryptographicStampQrMapper
{
    public function fields(
        CryptographicStamp $stamp,
        ?string $externallyProvisionedZatcaCaSignature = null,
    ): CryptographicQrFields {
        if ($externallyProvisionedZatcaCaSignature !== null && $externallyProvisionedZatcaCaSignature === '') {
            throw new CryptographicStampException(
                'Tag 9 cannot be an empty placeholder.',
                operation: 'qr_map',
                reason: 'empty_tag_9',
            );
        }

        return new CryptographicQrFields(
            ecdsaSignature: $stamp->signatureDerBase64,
            ecdsaPublicKey: $stamp->publicKeySpkiDer,
            zatcaCaSignature: $externallyProvisionedZatcaCaSignature,
        );
    }
}
