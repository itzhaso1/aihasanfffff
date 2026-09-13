<?php

namespace App\EInvoicing\QR;

/**
 * Phase 8 produces Tags 1–6 only. Tags 7–9 require a future cryptographic stamp.
 * This is not a production-complete ZATCA QR.
 */
enum QrPayloadProfile: string
{
    case Phase8Unsigned = 'phase8_unsigned';

    case WithCryptographicFields = 'with_cryptographic_fields';
}
