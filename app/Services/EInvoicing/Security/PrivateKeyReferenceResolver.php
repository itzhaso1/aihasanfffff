<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\PrivateKeyStoreException;
use App\Enums\EInvoicing\EInvoicingEnvironment;
use App\Models\EInvoicing\PrivateKeyReference;

/**
 * Fail-closed resolution of private-key references for signing boundaries.
 */
final class PrivateKeyReferenceResolver
{
    public function resolve(
        int $workspaceId,
        int $referenceId,
        EInvoicingEnvironment $environment,
        ?int $egsUnitId = null,
    ): PrivateKeyReference {
        $reference = PrivateKeyReference::withoutGlobalScopes()->find($referenceId);
        if ($reference === null) {
            throw new PrivateKeyStoreException(
                'Private key reference was not found.',
                referenceId: $referenceId,
                operation: 'resolve',
                reason: 'missing_reference',
            );
        }

        if ((int) $reference->workspace_id !== $workspaceId) {
            throw new PrivateKeyStoreException(
                'Private key reference does not belong to this workspace.',
                referenceId: $referenceId,
                operation: 'resolve',
                reason: 'workspace_mismatch',
            );
        }

        if ($egsUnitId !== null && (int) $reference->egs_unit_id !== $egsUnitId) {
            throw new PrivateKeyStoreException(
                'Private key reference does not belong to this EGS unit.',
                referenceId: $referenceId,
                operation: 'resolve',
                reason: 'egs_mismatch',
            );
        }

        if ($reference->environment !== $environment) {
            throw new PrivateKeyStoreException(
                'Private key reference environment does not match the requested environment.',
                referenceId: $referenceId,
                operation: 'resolve',
                reason: 'environment_mismatch',
            );
        }

        if (! $reference->isUsable()) {
            throw new PrivateKeyStoreException(
                'Private key reference is not usable.',
                referenceId: $referenceId,
                operation: 'resolve',
                reason: 'reference_not_active',
            );
        }

        return $reference;
    }
}
