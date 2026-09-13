<?php

namespace App\Services\Finance;

use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\IssuedDocumentSnapshot;

/**
 * Safe e-invoice artifact metadata for Web/API/Flutter.
 *
 * Does not expose certificates, private keys, or stamp material.
 * Does not claim FATOORA clearance or production signing.
 */
class EInvoiceArtifactService
{
    /**
     * @return array<string, mixed>
     */
    public function availabilityForInvoice(FinanceInvoice $invoice): array
    {
        $purchase = (string) $invoice->type === 'purchase';
        $issued = $invoice->isIssued();

        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->first();

        $record = null;
        $hasSecurity = false;
        if ($snapshot) {
            $record = EInvoiceDocumentRecord::withoutGlobalScopes()
                ->where('workspace_id', $invoice->workspace_id)
                ->where('issued_document_snapshot_id', $snapshot->id)
                ->first();
            if ($record) {
                $hasSecurity = EInvoiceSecurityRecord::withoutGlobalScopes()
                    ->where('workspace_id', $invoice->workspace_id)
                    ->where('e_invoice_document_id', $record->id)
                    ->exists();
            }
        }

        $xmlAvailable = $issued && ! $purchase && $snapshot !== null;
        $qrAvailable = $xmlAvailable && $hasSecurity;

        $reason = null;
        if ($purchase) {
            $reason = 'purchase_not_applicable';
        } elseif (! $issued) {
            $reason = 'not_issued';
        } elseif ($snapshot === null) {
            $reason = 'no_snapshot';
        } elseif (! $hasSecurity) {
            $reason = 'security_chain_pending';
        }

        return [
            'requirement' => $invoice->zatca_requirement,
            'tax_document_subtype' => $invoice->tax_document_subtype,
            'has_snapshot' => $snapshot !== null,
            'has_document' => $record !== null,
            'has_xml' => $xmlAvailable,
            'has_qr' => $qrAvailable,
            'xml_available' => $xmlAvailable,
            'qr_available' => $qrAvailable,
            'clearance' => false,
            'reporting' => false,
            'production_stamp' => false,
            'integration' => 'foundation',
            'not_applicable' => $purchase,
            'reason' => $reason,
        ];
    }
}
