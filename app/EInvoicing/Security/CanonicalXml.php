<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\InvoiceHashException;
use Stringable;

/**
 * Canonical XML octet string used as the SHA-256 input (BR-KSA-26).
 */
final readonly class CanonicalXml implements Stringable
{
    public function __construct(private string $value)
    {
        if ($this->value === '') {
            throw new InvoiceHashException(
                'Canonical XML must not be empty.',
                operation: 'canonicalize',
                reason: 'empty_canonical_xml',
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
