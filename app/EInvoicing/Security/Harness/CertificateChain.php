<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\CryptographicCertificate;
use App\EInvoicing\Security\Exceptions\CertificateException;

/**
 * In-memory certificate chain representation. Not a production CSID.
 * Private keys are forbidden.
 */
final readonly class CertificateChain
{
    /**
     * @param  list<CryptographicCertificate>  $certificates
     */
    public function __construct(
        public array $certificates,
        public string $profileIdentifier = 'test.certificate_chain',
    ) {
        if ($this->certificates === []) {
            throw new CertificateException(
                'Certificate chain must contain at least one certificate.',
                operation: 'certificate_chain',
                reason: 'empty_chain',
            );
        }
        foreach ($this->certificates as $certificate) {
            if (! $certificate->testFixture) {
                throw new CertificateException(
                    'Harness certificate chains accept test fixtures only. Production CSID cannot be created here.',
                    operation: 'certificate_chain',
                    reason: 'non_test_certificate',
                );
            }
        }
    }

    public function leaf(): CryptographicCertificate
    {
        return $this->certificates[0];
    }

    /**
     * @return list<string>
     */
    public function subjects(): array
    {
        return array_map(
            static fn (CryptographicCertificate $certificate): string => $certificate->subject,
            $this->certificates,
        );
    }
}
