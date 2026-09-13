<?php

namespace App\EInvoicing\Security;

/**
 * Official Security Features 2.2.1 req. 16: SHA-256 + ECDSA + 256-bit key.
 *
 * Curve is intentionally named as a test profile. Official documents conflict:
 * Security Features 2.2.2 says P-256; Detailed Technical Guidelines use secp256k1.
 * This constant is not a production ZATCA curve claim.
 */
final class SigningAlgorithm
{
    public const HASH = 'SHA-256';

    public const ASYMMETRIC = 'ECDSA';

    public const KEY_LENGTH_BITS = 256;

    public const TEST_CURVE = 'secp256k1';

    public const TEST_PROFILE = 'ECDSA-SHA256-secp256k1-TEST-ONLY';

    public const SIGNED_INPUT = 'phase7_invoice_hash_sha256_binary';

    public const SIGNATURE_ENCODING = 'ecdsa-sha256-der-base64';

    public const PUBLIC_KEY_ENCODING = 'subject-public-key-info-der';
}
