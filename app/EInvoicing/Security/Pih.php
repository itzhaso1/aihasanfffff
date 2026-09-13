<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\SecurityChainException;
use JsonSerializable;
use Stringable;

/**
 * Previous Invoice Hash (KSA-13 / BR-KSA-26).
 *
 * First document in an EGS sequence uses the official constant (Base64 of the
 * ASCII hex SHA-256 of the character "0"). Subsequent documents use the
 * previous document's invoice hash (Base64 of the binary SHA-256 digest).
 *
 * This is not an invoice number, database id, or timestamp.
 */
final readonly class Pih implements JsonSerializable, Stringable
{
    /**
     * Official first-document PIH from ZATCA XML Implementation Standard
     * BR-KSA-26 (19 May 2023): "equivalent for base64 encoded SHA256 of '0'
     * (zero) character".
     */
    public const FIRST_DOCUMENT = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    private function __construct(private string $value) {}

    public static function firstDocument(): self
    {
        return new self(self::FIRST_DOCUMENT);
    }

    public static function fromInvoiceHash(InvoiceHash $hash): self
    {
        return new self($hash->value());
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new SecurityChainException(
                'PIH must not be empty. The first document uses the official constant, not an empty string.',
                operation: 'validate_pih',
                reason: 'empty',
            );
        }

        if ($trimmed === self::FIRST_DOCUMENT) {
            return self::firstDocument();
        }

        $decoded = base64_decode($trimmed, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new SecurityChainException(
                'PIH must be the official first-document constant or Base64 of a 32-byte SHA-256 digest.',
                operation: 'validate_pih',
                reason: 'invalid_encoding',
            );
        }

        if (base64_encode($decoded) !== $trimmed) {
            throw new SecurityChainException(
                'PIH must use canonical Base64 encoding.',
                operation: 'validate_pih',
                reason: 'non_canonical_base64',
            );
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isFirstDocument(): bool
    {
        return $this->value === self::FIRST_DOCUMENT;
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
