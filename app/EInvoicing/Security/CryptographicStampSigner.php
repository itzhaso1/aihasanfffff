<?php

namespace App\EInvoicing\Security;

/**
 * Cryptographic boundary only.
 *
 * Does not calculate tax, ICV, PIH, invoice hash, or QR.
 * Production stamping requires a CSID issued by ZATCA.
 */
interface CryptographicStampSigner
{
    public function isProductionIdentity(): bool;

    /**
     * True only for explicit local test signers. Production containers must
     * never bind a test-only signer, and test signers must not run as production.
     */
    public function isTestOnly(): bool;

    public function signCanonicalDigest(InvoiceHash $hash): CryptographicStampResult;

    public function sign(SigningInput $input): CryptographicStamp;
}
