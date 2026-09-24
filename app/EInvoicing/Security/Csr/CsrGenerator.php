<?php

namespace App\EInvoicing\Security\Csr;

/**
 * CSR + dev/test key generation boundary (implementation must not expose PEM to callers).
 */
interface CsrGenerator
{
    /**
     * @return array{private_key_pem: string, csr_pem: string, public_key_fingerprint: string}
     */
    public function generate(CsrSubjectData $subject): array;
}
