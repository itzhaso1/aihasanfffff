<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\Exceptions\CryptographicStampException;

/**
 * TEST ONLY. Identity encoding of OpenSSL ECDSA ASN.1 DER.
 */
final class TestDerSignatureEncoding implements TestSignatureEncoding
{
    public const ID = 'test.der';

    public function id(): string
    {
        return self::ID;
    }

    public function encode(string $opensslDer): string
    {
        $this->assertDer($opensslDer);

        return $opensslDer;
    }

    public function decode(string $encoded): string
    {
        $this->assertDer($encoded);

        return $encoded;
    }

    private function assertDer(string $der): void
    {
        if ($der === '' || ord($der[0]) !== 0x30) {
            throw new CryptographicStampException(
                'Test DER encoding requires an ASN.1 SEQUENCE.',
                operation: 'test_encoding',
                reason: 'invalid_der',
            );
        }
    }
}
