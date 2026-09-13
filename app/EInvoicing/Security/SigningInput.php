<?php

namespace App\EInvoicing\Security;

/**
 * Bytes presented to the cryptographic boundary.
 *
 * Official Security Features definition: the Cryptographic Stamp is the
 * technical digital signature of the hash of the document.
 * Phase 7 already produced that hash. This object does not re-hash XML.
 *
 * XAdES ds:SignedInfo signing is a separate, currently blocked profile.
 */
final readonly class SigningInput
{
    public function __construct(
        public InvoiceHash $invoiceHash,
        public int $workspaceId,
        public int $egsUnitId,
        public int $eInvoiceDocumentId,
        public string $documentIdentity,
        public CryptographicCertificate $certificate,
    ) {}

    /**
     * SHA-256 digest bytes of the Phase 7 invoice hash (32 bytes).
     */
    public function digestBytes(): string
    {
        return $this->invoiceHash->binary();
    }

    public function signedInputIdentifier(): string
    {
        return SigningAlgorithm::SIGNED_INPUT;
    }
}
