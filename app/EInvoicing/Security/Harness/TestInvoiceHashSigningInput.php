<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\InvoiceHash;

/**
 * TEST ONLY candidate: sign the persisted Phase 7 32-byte SHA-256 digest.
 * Not the production ZATCA signing-input decision.
 */
final class TestInvoiceHashSigningInput implements TestSigningInputStrategy
{
    public const ID = 'test.invoice_hash';

    public function id(): string
    {
        return self::ID;
    }

    public function payload(InvoiceHash $invoiceHash): string
    {
        return $invoiceHash->binary();
    }
}
