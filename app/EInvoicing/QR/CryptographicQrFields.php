<?php

namespace App\EInvoicing\QR;

/**
 * Optional Tags 7–9. Production Phase 8 never populates these.
 * Tests may supply deterministic fixtures; that is not a ZATCA identity.
 */
final readonly class CryptographicQrFields
{
    public function __construct(
        public ?string $ecdsaSignature = null,
        public ?string $ecdsaPublicKey = null,
        public ?string $zatcaCaSignature = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function hasAny(): bool
    {
        return $this->ecdsaSignature !== null
            || $this->ecdsaPublicKey !== null
            || $this->zatcaCaSignature !== null;
    }
}
