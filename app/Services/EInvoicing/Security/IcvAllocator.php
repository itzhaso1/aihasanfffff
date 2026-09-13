<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\IcvAllocationException;
use App\EInvoicing\Security\Icv;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\Pih;
use App\Models\EInvoicing\EgsUnit;

/**
 * Transactional ICV allocation and PIH lookup for one locked EGS unit.
 *
 * Callers must hold a row lock (lockForUpdate) inside a database transaction.
 * ICV is not MAX(id), cache, invoice number, or a Flutter counter.
 */
final class IcvAllocator
{
    public function lock(int $egsUnitId): EgsUnit
    {
        $unit = EgsUnit::withoutGlobalScopes()
            ->whereKey($egsUnitId)
            ->lockForUpdate()
            ->first();

        if ($unit === null) {
            throw new IcvAllocationException(
                'EGS unit was not found while allocating ICV.',
                sequenceIdentity: 'egs:'.$egsUnitId,
                operation: 'lock_sequence',
                reason: 'missing_egs',
            );
        }

        if (! $unit->isActive()) {
            throw new IcvAllocationException(
                'EGS unit is not active.',
                sequenceIdentity: 'egs:'.$unit->uuid,
                operation: 'lock_sequence',
                reason: 'inactive',
            );
        }

        return $unit;
    }

    public function nextIcv(EgsUnit $lockedUnit): Icv
    {
        return Icv::fromInt((int) $lockedUnit->next_icv);
    }

    public function previousHash(EgsUnit $lockedUnit): Pih
    {
        $last = $lockedUnit->last_invoice_hash;
        if ($last === null || $last === '') {
            return Pih::firstDocument();
        }

        return Pih::fromString((string) $last);
    }

    public function commit(EgsUnit $lockedUnit, Icv $allocated, InvoiceHash $hash): void
    {
        $expected = (int) $lockedUnit->next_icv;
        if ($allocated->value() !== $expected) {
            throw new IcvAllocationException(
                'Allocated ICV does not match the locked sequence head.',
                sequenceIdentity: 'egs:'.$lockedUnit->uuid,
                operation: 'commit_sequence',
                reason: 'stale_allocation',
            );
        }

        $lockedUnit->next_icv = $allocated->value() + 1;
        $lockedUnit->last_invoice_hash = $hash->value();
        $lockedUnit->save();
    }
}
