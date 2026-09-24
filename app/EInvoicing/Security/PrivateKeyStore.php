<?php

namespace App\EInvoicing\Security;

use App\Models\EInvoicing\PrivateKeyReference;

/**
 * Boundary for private-key material. Implementations must never persist PEM in application DB tables.
 */
interface PrivateKeyStore
{
    /**
     * Persist key material in the backing store and return the opaque store key.
     *
     * @param  non-empty-string  $publicKeyFingerprint  Metadata only (e.g. SHA-256 hex of SPKI/cert).
     */
    public function store(
        PrivateKeyReference $reference,
        string $privateKeyPem,
        string $publicKeyFingerprint,
    ): string;

    /**
     * Sign a digest using the referenced key. Returns signature bytes (algorithm TBD in later phases).
     */
    public function sign(PrivateKeyReference $reference, string $digest): string;

    public function health(PrivateKeyReference $reference): bool;

    public function destroy(PrivateKeyReference $reference): void;
}
