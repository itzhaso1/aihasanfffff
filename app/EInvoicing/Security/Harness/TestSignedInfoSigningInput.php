<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\InvoiceHash;

/**
 * TEST ONLY candidate: sign synthetic canonical SignedInfo bytes.
 *
 * This is not production XAdES and not XMLDSig C14N. It proves the
 * architecture can sign SignedInfo-shaped input that *references*
 * invoice_hash without mutating invoice_hash.
 */
final class TestSignedInfoSigningInput implements TestSigningInputStrategy
{
    public const ID = 'test.signed_info';

    public const MARKER = 'TEST_ONLY_SIGNED_INFO';

    public function id(): string
    {
        return self::ID;
    }

    public function payload(InvoiceHash $invoiceHash): string
    {
        return self::MARKER
            ."\nCanonicalizationMethod=TEST_ONLY"
            ."\nSignatureMethod=TEST_ONLY"
            ."\nDigestMethod=SHA-256"
            ."\nDigestValue=".$invoiceHash->value()
            ."\n";
    }
}
