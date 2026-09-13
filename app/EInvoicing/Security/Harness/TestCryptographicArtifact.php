<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\PublicKey;

/**
 * TEST ONLY bundle consumed by QR adapters. Private keys are never included.
 */
final readonly class TestCryptographicArtifact
{
    public function __construct(
        public SignatureArtifact $signature,
        public PublicKey $publicKey,
        public string $profileIdentifier,
    ) {}
}
