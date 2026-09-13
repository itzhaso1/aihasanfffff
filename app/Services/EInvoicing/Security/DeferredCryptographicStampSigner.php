<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\CryptographicStamp;
use App\EInvoicing\Security\CryptographicStampResult;
use App\EInvoicing\Security\CryptographicStampSigner;
use App\EInvoicing\Security\Exceptions\CryptographicStampException;
use App\EInvoicing\Security\Exceptions\EInvoiceSecurityException;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\SigningInput;

/**
 * Default signer: production stamping is deferred until CSID provisioning.
 * This is not a ZATCA identity and does not contact ZATCA.
 */
final class DeferredCryptographicStampSigner implements CryptographicStampSigner
{
    public function isProductionIdentity(): bool
    {
        return false;
    }

    public function isTestOnly(): bool
    {
        return false;
    }

    public function signCanonicalDigest(InvoiceHash $hash): CryptographicStampResult
    {
        unset($hash);

        return CryptographicStampResult::deferred();
    }

    public function sign(SigningInput $input): CryptographicStamp
    {
        unset($input);

        throw new CryptographicStampException(
            'Cryptographic stamp is deferred until a ZATCA-issued CSID is provisioned. Production ZATCA cryptographic profile is unresolved and unavailable. This signer is not a production identity.',
            operation: 'cryptographic_stamp',
            reason: 'stamp_deferred',
        );
    }

    public function assertNotProduction(): void
    {
        if ($this->isProductionIdentity()) {
            throw new EInvoiceSecurityException(
                'A production ZATCA identity must not be used in this phase.',
                operation: 'cryptographic_stamp',
                reason: 'production_identity_forbidden',
            );
        }
    }
}
