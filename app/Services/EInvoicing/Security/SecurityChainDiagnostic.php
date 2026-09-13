<?php

namespace App\Services\EInvoicing\Security;

use App\Models\EInvoicing\EgsUnit;
use App\Models\EInvoicing\EInvoiceSecurityRecord;

/**
 * Read-only diagnostic view of an EGS security sequence.
 *
 * This phase does not rebuild, reset, or rewrite historical ICV/PIH/hash values.
 */
final class SecurityChainDiagnostic
{
    /**
     * @return array{
     *     egs_unit_id: int,
     *     workspace_id: int,
     *     uuid: string,
     *     next_icv: int,
     *     last_invoice_hash: ?string,
     *     documents: list<array{icv: int, pih: string, invoice_hash: string, e_invoice_document_id: int}>
     * }
     */
    public function describe(int $egsUnitId): array
    {
        $unit = EgsUnit::withoutGlobalScopes()->findOrFail($egsUnitId);
        $rows = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('egs_unit_id', $unit->id)
            ->orderBy('icv')
            ->get(['icv', 'pih', 'invoice_hash', 'e_invoice_document_id']);

        return [
            'egs_unit_id' => (int) $unit->id,
            'workspace_id' => (int) $unit->workspace_id,
            'uuid' => (string) $unit->uuid,
            'next_icv' => (int) $unit->next_icv,
            'last_invoice_hash' => $unit->last_invoice_hash !== null ? (string) $unit->last_invoice_hash : null,
            'documents' => $rows->map(static fn (EInvoiceSecurityRecord $row): array => [
                'icv' => (int) $row->icv,
                'pih' => (string) $row->pih,
                'invoice_hash' => (string) $row->invoice_hash,
                'e_invoice_document_id' => (int) $row->e_invoice_document_id,
            ])->all(),
        ];
    }
}
