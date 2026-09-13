<?php

namespace App\EInvoicing\Security;

/**
 * Public certificate metadata. This is not a ZATCA CSID unless the issuer
 * is actually ZATCA's technical CA — which Phase 9 never claims.
 */
final readonly class CryptographicCertificate
{
    public function __construct(
        public CertificateFingerprint $fingerprint,
        public string $serialNumber,
        public string $subject,
        public string $issuer,
        public string $notBefore,
        public string $notAfter,
        public string $publicCertificatePem,
        public PublicKey $publicKey,
        public string $signatureAlgorithm,
        public bool $testFixture,
    ) {}
}
