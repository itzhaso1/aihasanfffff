<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\IcvAllocationException;
use JsonSerializable;
use Stringable;

/**
 * Invoice Counter Value (KSA-16 / BR-KSA-33).
 *
 * Digits-only monotonic counter scoped to an EGS unit. Not a database id,
 * invoice number, UUID, timestamp, or hash.
 */
final readonly class Icv implements JsonSerializable, Stringable
{
    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new IcvAllocationException(
                'ICV must be a positive integer and cannot be reset or negative.',
                operation: 'validate',
                reason: 'non_positive',
            );
        }

        return new self($value);
    }

    public static function fromString(string $value): self
    {
        if ($value === '' || ! ctype_digit($value)) {
            throw new IcvAllocationException(
                'ICV must contain digits only.',
                operation: 'validate',
                reason: 'non_digit',
            );
        }

        if (strlen($value) > 1 && $value[0] === '0') {
            throw new IcvAllocationException(
                'ICV must not have leading zeros.',
                operation: 'validate',
                reason: 'leading_zeros',
            );
        }

        return self::fromInt((int) $value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function toXmlDigits(): string
    {
        return (string) $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->toXmlDigits();
    }
}
