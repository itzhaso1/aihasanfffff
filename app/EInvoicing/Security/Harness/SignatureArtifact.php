<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\Exceptions\CryptographicStampException;
use App\EInvoicing\Security\InvoiceHash;

/**
 * TEST ONLY signature bytes plus the profile that produced them.
 * Not a production ZATCA cryptographic stamp.
 */
final readonly class SignatureArtifact
{
    public function __construct(
        public InvoiceHash $invoiceHash,
        public string $bytes,
        public string $encodingId,
        public string $signingInputId,
        public string $profileIdentifier,
    ) {
        if (! str_starts_with($this->encodingId, 'test.')
            || ! str_starts_with($this->signingInputId, 'test.')
            || ! str_starts_with($this->profileIdentifier, 'test.')) {
            throw new CryptographicStampException(
                'Harness signature artifacts must carry test-only identifiers.',
                operation: 'signature_artifact',
                reason: 'non_test_identifier',
            );
        }
        if ($this->bytes === '') {
            throw new CryptographicStampException(
                'Signature artifact bytes must not be empty.',
                operation: 'signature_artifact',
                reason: 'empty_signature',
            );
        }
    }
}
