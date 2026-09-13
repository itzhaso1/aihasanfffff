<?php

namespace App\EInvoicing\Security;

/**
 * EGS-scoped public cryptographic identity metadata.
 * Private key material is never part of this object.
 */
final readonly class CryptographicIdentity
{
    public function __construct(
        public int $workspaceId,
        public int $egsUnitId,
        public CryptographicCertificate $certificate,
        public StampStatus $usableFor,
    ) {}

    public function isProductionIdentity(): bool
    {
        return $this->usableFor === StampStatus::ProductionSigned;
    }
}
