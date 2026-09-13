<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\CryptographicStampException;

/**
 * Official XML stamp packaging is XAdES-B-B enveloped (Security Features
 * 2.2.1 req. 10, 11, 15; ETSI EN 319 132-1).
 *
 * Phase 9 does not emit UBLExtensions/Signature XML because:
 * 1. Official docs conflict on whether ECDSA signs the invoice hash or
 *    canonical ds:SignedInfo.
 * 2. A production SigningCertificate requires a ZATCA-issued CSID.
 * 3. Emitting a self-signed XAdES block would pretend HASEM has a ZATCA identity.
 */
final class XadesEnvelopedSignature
{
    public function materialize(): never
    {
        throw new CryptographicStampException(
            'Production XAdES-B-B is blocked until a ZATCA-issued CSID exists and the official signing-input conflict is resolved.',
            operation: 'xades',
            reason: 'xades_blocked',
        );
    }
}
