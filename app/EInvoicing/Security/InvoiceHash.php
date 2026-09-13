<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\InvoiceHashException;
use JsonSerializable;
use Stringable;

/**
 * Invoice hash / digest (KSA-12 / BR-KSA-26).
 *
 * Representation: Base64(SHA-256 binary digest of canonical XML).
 * Not a UUID, ICV, invoice number, or cryptographic stamp.
 */
final readonly class InvoiceHash implements JsonSerializable, Stringable
{
    private function __construct(private string $value) {}

    public static function fromBinary(string $binary): self
    {
        if (strlen($binary) !== 32) {
            throw new InvoiceHashException(
                'Invoice hash binary digest must be 32 bytes (SHA-256).',
                operation: 'validate_hash',
                reason: 'invalid_digest_length',
            );
        }

        return new self(base64_encode($binary));
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        $decoded = base64_decode($trimmed, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new InvoiceHashException(
                'Invoice hash must be Base64 of a 32-byte SHA-256 digest.',
                operation: 'validate_hash',
                reason: 'invalid_encoding',
            );
        }

        if (base64_encode($decoded) !== $trimmed) {
            throw new InvoiceHashException(
                'Invoice hash must use canonical Base64 encoding.',
                operation: 'validate_hash',
                reason: 'non_canonical_base64',
            );
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function binary(): string
    {
        return base64_decode($this->value, true) ?: '';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
