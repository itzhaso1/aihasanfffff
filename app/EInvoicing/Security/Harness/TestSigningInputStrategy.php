<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\InvoiceHash;

/**
 * TEST ONLY. Selects which bytes ECDSA signs. Not a production ZATCA rule.
 */
interface TestSigningInputStrategy
{
    public function id(): string;

    /**
     * Bytes presented to the test ECDSA signer. Must not recompute invoice_hash.
     */
    public function payload(InvoiceHash $invoiceHash): string;
}
