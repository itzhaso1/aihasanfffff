<?php

namespace App\Enums\EInvoicing;

enum EInvoicingEnvironment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function assertMatches(EInvoicingEnvironment $other): void
    {
        if ($this !== $other) {
            throw new \InvalidArgumentException(
                'E-invoicing environment mismatch: expected '.$this->value.', got '.$other->value.'.',
            );
        }
    }
}
