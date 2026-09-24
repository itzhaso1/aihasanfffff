<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\PrivateKeyStoreException;
use App\EInvoicing\Security\PrivateKeyStore;
use App\Enums\EInvoicing\PrivateKeyStoreDriver;
use App\Models\EInvoicing\PrivateKeyReference;

/**
 * TEST/LOCAL ONLY — holds PEM in process memory. Not production-safe.
 */
final class DevMemoryPrivateKeyStore implements PrivateKeyStore
{
    /** @var array<string, string> store_key => pem (never logged) */
    private array $material = [];

    public function __construct(
        private readonly PrivateKeyStoreEnvironmentGuard $guard,
    ) {}

    public function store(
        PrivateKeyReference $reference,
        string $privateKeyPem,
        string $publicKeyFingerprint,
    ): string {
        $this->assertDriver($reference);
        $this->guard->assertDevDriverAllowed(PrivateKeyStoreDriver::DevMemory->value);
        $this->assertNoPemInFingerprint($publicKeyFingerprint);
        $pem = trim($privateKeyPem);
        if ($pem === '' || ! str_contains($pem, 'PRIVATE KEY')) {
            throw new PrivateKeyStoreException(
                'Invalid private key material for dev store.',
                referenceId: (int) $reference->id,
                operation: 'store',
                reason: 'invalid_key_material',
            );
        }

        $key = (string) $reference->store_key;
        if ($key === '') {
            throw new PrivateKeyStoreException(
                'Private key reference is missing store_key.',
                referenceId: (int) $reference->id,
                operation: 'store',
                reason: 'missing_store_key',
            );
        }

        $this->material[$key] = $pem;

        return $key;
    }

    public function sign(PrivateKeyReference $reference, string $digest): string
    {
        $this->assertUsableReference($reference);
        $this->guard->assertDevDriverAllowed(PrivateKeyStoreDriver::DevMemory->value);
        $pem = $this->material[(string) $reference->store_key] ?? null;
        if ($pem === null) {
            throw new PrivateKeyStoreException(
                'Private key material was not found in dev store.',
                referenceId: (int) $reference->id,
                operation: 'sign',
                reason: 'missing_material',
            );
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new PrivateKeyStoreException(
                'Dev store could not load private key.',
                referenceId: (int) $reference->id,
                operation: 'sign',
                reason: 'openssl_load_failed',
            );
        }

        $signature = '';
        $ok = openssl_sign($digest, $signature, $key, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new PrivateKeyStoreException(
                'Dev store signing failed.',
                referenceId: (int) $reference->id,
                operation: 'sign',
                reason: 'sign_failed',
            );
        }

        return $signature;
    }

    public function health(PrivateKeyReference $reference): bool
    {
        try {
            $this->assertUsableReference($reference);
        } catch (PrivateKeyStoreException) {
            return false;
        }

        if ($this->guard->isProductionEnvironment()) {
            return false;
        }

        return isset($this->material[(string) $reference->store_key]);
    }

    public function destroy(PrivateKeyReference $reference): void
    {
        $this->assertDriver($reference);
        unset($this->material[(string) $reference->store_key]);
    }

    /** Test helper — clears in-memory material between tests. */
    public function flushMemory(): void
    {
        $this->material = [];
    }

    private function assertDriver(PrivateKeyReference $reference): void
    {
        if ($reference->store_driver !== PrivateKeyStoreDriver::DevMemory) {
            throw new PrivateKeyStoreException(
                'Dev memory store cannot operate on this driver.',
                referenceId: (int) $reference->id,
                operation: 'private_key_store',
                reason: 'driver_mismatch',
            );
        }
    }

    private function assertUsableReference(PrivateKeyReference $reference): void
    {
        $this->assertDriver($reference);
        if (! $reference->isUsable()) {
            throw new PrivateKeyStoreException(
                'Private key reference is not active.',
                referenceId: (int) $reference->id,
                operation: 'private_key_store',
                reason: 'reference_not_active',
            );
        }
    }

    private function assertNoPemInFingerprint(string $fingerprint): void
    {
        if (str_contains($fingerprint, 'BEGIN')) {
            throw new PrivateKeyStoreException(
                'Fingerprint must not contain PEM material.',
                operation: 'store',
                reason: 'fingerprint_contains_pem',
            );
        }
    }
}
