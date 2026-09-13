<?php

namespace App\EInvoicing\QR\Harness;

use App\EInvoicing\Security\Exceptions\ProductionCryptographicProfileException;

/**
 * Production Tag 9 cannot be generated locally (Phase 9A: EXTERNAL ZATCA ARTIFACT).
 */
final class UnresolvedProductionQrTag9Provider implements QrTag9Provider
{
    public function artifact(): never
    {
        throw new ProductionCryptographicProfileException(
            'Production ZATCA cryptographic profile is unresolved and unavailable.',
            operation: 'tag_9',
            reason: 'tag_9_external_unresolved',
        );
    }
}
