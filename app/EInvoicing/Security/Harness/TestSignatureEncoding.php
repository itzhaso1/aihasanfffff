<?php

namespace App\EInvoicing\Security\Harness;

/**
 * TEST ONLY. Converts OpenSSL ECDSA DER to a chosen test representation.
 * Not a production QR/XML signature encoding decision.
 */
interface TestSignatureEncoding
{
    public function id(): string;

    public function encode(string $opensslDer): string;

    public function decode(string $encoded): string;
}
