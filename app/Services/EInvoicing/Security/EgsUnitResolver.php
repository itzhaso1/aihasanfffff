<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\IcvAllocationException;
use App\Models\EInvoicing\EgsUnit;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Resolves the EGS unit that owns an ICV/PIH sequence.
 * Workspace is not treated as an EGS unit.
 */
final class EgsUnitResolver
{
    public function defaultForWorkspace(int $workspaceId): EgsUnit
    {
        $uuid = EgsUnit::defaultUuidForWorkspace($workspaceId);

        $existing = EgsUnit::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $uuid)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return EgsUnit::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'uuid' => $uuid,
                'name' => 'Default EGS unit',
                'next_icv' => 1,
                'last_invoice_hash' => null,
                'status' => EgsUnit::STATUS_ACTIVE,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = EgsUnit::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('uuid', $uuid)
                ->first();
            if ($existing) {
                return $existing;
            }

            throw new IcvAllocationException(
                'Failed to resolve the default EGS unit.',
                sequenceIdentity: 'workspace:'.$workspaceId,
                operation: 'resolve_egs',
                reason: 'create_race',
            );
        }
    }

    public function requireForWorkspace(int $egsUnitId, int $workspaceId): EgsUnit
    {
        $unit = EgsUnit::withoutGlobalScopes()->find($egsUnitId);
        if ($unit === null || (int) $unit->workspace_id !== $workspaceId) {
            throw new IcvAllocationException(
                'EGS unit does not belong to the document workspace.',
                sequenceIdentity: 'egs:'.$egsUnitId,
                operation: 'resolve_egs',
                reason: 'workspace_mismatch',
            );
        }

        if (! $unit->isActive()) {
            throw new IcvAllocationException(
                'EGS unit is not active.',
                sequenceIdentity: 'egs:'.$unit->uuid,
                operation: 'resolve_egs',
                reason: 'inactive',
            );
        }

        return $unit;
    }
}
