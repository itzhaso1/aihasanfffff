<?php

namespace App\EInvoicing\Security;

/**
 * Official invoice-hash algorithm for this phase.
 *
 * ZATCA Electronic Invoice XML Implementation Standard (19 May 2023) BR-KSA-26
 * and the E-invoicing Detailed Technical Guideline specify SHA-256 over the
 * canonical XML, then Base64-encode the binary digest.
 *
 * Callers must not scatter hash('sha256', ...) through the application.
 */
final class HashAlgorithm
{
    public const SHA_256 = 'SHA-256';

    public static function name(): string
    {
        return self::SHA_256;
    }

    public static function sha256Binary(string $payload): string
    {
        return hash('sha256', $payload, true);
    }

    public static function sha256Base64(string $payload): string
    {
        return base64_encode(self::sha256Binary($payload));
    }
}
