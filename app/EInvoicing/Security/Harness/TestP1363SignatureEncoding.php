<?php

namespace App\EInvoicing\Security\Harness;

/**
 * TEST ONLY. IEEE P1363 r||s (32+32 bytes for the 256-bit test keys).
 * Not the production ZATCA Tag 7 / SignatureValue encoding.
 */
final class TestP1363SignatureEncoding implements TestSignatureEncoding
{
    public const ID = 'test.p1363';

    public function __construct(
        private readonly TestEcdsaSignatureCodec $codec = new TestEcdsaSignatureCodec,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function encode(string $opensslDer): string
    {
        return $this->codec->derToP1363($opensslDer);
    }

    public function decode(string $encoded): string
    {
        return $this->codec->p1363ToDer($encoded);
    }
}
