<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\CertificateException;

/**
 * Public key only. Never holds a private key.
 */
final readonly class PublicKey
{
    public function __construct(
        public string $spkiDer,
        public string $algorithm,
        public ?string $curve,
        public int $bitLength,
    ) {
        if ($this->spkiDer === '') {
            throw new CertificateException(
                'Public key SPKI DER must not be empty.',
                operation: 'public_key',
                reason: 'empty_spki',
            );
        }
    }

    public function spkiBase64(): string
    {
        return base64_encode($this->spkiDer);
    }
}
