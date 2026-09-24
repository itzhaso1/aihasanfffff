<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\PrivateKeyStoreException;
use App\EInvoicing\Security\PrivateKeyStore;
use App\Enums\EInvoicing\EInvoicingEnvironment;
use App\Enums\EInvoicing\PrivateKeyReferenceStatus;
use App\Enums\EInvoicing\PrivateKeyStoreDriver;
use App\Models\EInvoicing\PrivateKeyReference;
use Illuminate\Support\Str;

/**
 * Persists {@see PrivateKeyReference} rows and coordinates with {@see PrivateKeyStore}.
 * Does not expose PEM via API or models.
 */
final class PrivateKeyReferenceService
{
    public function __construct(
        private readonly PrivateKeyStore $store,
        private readonly EgsUnitResolver $egsUnits,
        private readonly PrivateKeyStoreEnvironmentGuard $guard,
    ) {}

    /**
     * @param  non-empty-string  $publicKeyFingerprint
     */
    public function createActiveDevReference(
        int $workspaceId,
        int $egsUnitId,
        EInvoicingEnvironment $environment,
        string $publicKeyFingerprint,
        string $privateKeyPemForDevOnly,
        PrivateKeyStoreDriver $driver = PrivateKeyStoreDriver::DevMemory,
    ): PrivateKeyReference {
        $this->guard->assertDevDriverAllowed($driver->value);
        $this->egsUnits->requireForWorkspace($egsUnitId, $workspaceId);

        if ($driver !== PrivateKeyStoreDriver::DevMemory) {
            throw new PrivateKeyStoreException(
                'Only the dev memory driver is available in this phase.',
                operation: 'create_reference',
                reason: 'driver_not_implemented',
            );
        }

        $storeKey = (string) Str::uuid();

        $reference = PrivateKeyReference::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'egs_unit_id' => $egsUnitId,
            'environment' => $environment,
            'store_driver' => $driver,
            'store_key' => $storeKey,
            'public_key_fingerprint' => $publicKeyFingerprint,
            'status' => PrivateKeyReferenceStatus::Active,
        ]);

        $this->store->store($reference, $privateKeyPemForDevOnly, $publicKeyFingerprint);

        return $reference->fresh() ?? $reference;
    }

    public function revoke(PrivateKeyReference $reference): PrivateKeyReference
    {
        if ($reference->status === PrivateKeyReferenceStatus::Destroyed) {
            throw new PrivateKeyStoreException(
                'Destroyed private key references cannot be revoked.',
                referenceId: (int) $reference->id,
                operation: 'revoke',
                reason: 'already_destroyed',
            );
        }

        if ($reference->status === PrivateKeyReferenceStatus::Revoked) {
            return $reference;
        }

        $reference->status = PrivateKeyReferenceStatus::Revoked;
        $reference->revoked_at = now();
        $reference->save();

        return $reference->fresh() ?? $reference;
    }

    public function destroy(PrivateKeyReference $reference): PrivateKeyReference
    {
        if ($reference->status === PrivateKeyReferenceStatus::Destroyed) {
            return $reference;
        }

        $this->store->destroy($reference);

        $reference->status = PrivateKeyReferenceStatus::Destroyed;
        $reference->destroyed_at = now();
        if ($reference->revoked_at === null) {
            $reference->revoked_at = now();
        }
        $reference->save();

        return $reference->fresh() ?? $reference;
    }
}
