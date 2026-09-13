<?php

namespace App\Services\EInvoicing;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\QR\QrEncodingException;
use App\EInvoicing\Security\Exceptions\EInvoiceSecurityException;
use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Exceptions\Api\ProductionCryptoUnavailableException;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Services\EInvoicing\QR\EInvoiceQrService;
use App\Services\EInvoicing\Security\EInvoiceSecurityService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single application boundary from an issued snapshot into the e-invoice domain.
 *
 * Does not issue Finance/POS business documents, recalculate tax, persist QR,
 * or perform production ZATCA cryptographic signing.
 */
final class InvoiceIssueService
{
    public function __construct(
        private readonly EInvoiceFactory $factory,
        private readonly EInvoiceXmlGenerator $xmlGenerator,
        private readonly EInvoiceSecurityService $securityService,
        private readonly EInvoiceQrService $qrService,
    ) {}

    public function prepareFromSnapshot(IssuedDocumentSnapshot $snapshot): InvoiceIssueResult
    {
        $existing = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('issued_document_snapshot_id', $snapshot->id)
            ->first();

        $record = $this->factory->persist($snapshot);
        $document = $this->factory->make($snapshot);
        $reused = $existing !== null && (int) $existing->id === (int) $record->id;

        if (! $this->isXmlEligible($document)) {
            return new InvoiceIssueResult(
                snapshot: $snapshot,
                record: $record->fresh() ?? $record,
                document: $document,
                reusedDocument: $reused,
                xmlAvailable: false,
                securityGenerated: false,
                qrAvailable: false,
                xmlSkipReason: $this->xmlIneligibilityReason($document),
                securitySkipReason: 'xml_not_eligible',
                qrSkipReason: 'xml_not_eligible',
            );
        }

        try {
            $xml = $this->xmlGenerator->generate($document);
        } catch (EInvoiceXmlMappingException $exception) {
            Log::notice('e_invoice.xml.skipped', [
                'workspace_id' => $document->workspaceId,
                'source_type' => $document->sourceType,
                'source_id' => $document->sourceId,
                'field' => $exception->fieldPath,
            ]);

            return new InvoiceIssueResult(
                snapshot: $snapshot,
                record: $record->fresh() ?? $record,
                document: $document,
                reusedDocument: $reused,
                xmlAvailable: false,
                securityGenerated: false,
                qrAvailable: false,
                xmlSkipReason: 'xml_mapping_incomplete',
                securitySkipReason: 'xml_unavailable',
                qrSkipReason: 'xml_unavailable',
            );
        }

        $securityGenerated = false;
        $securitySkipReason = null;

        try {
            $this->securityService->generate($document, $xml);
            $securityGenerated = true;
            $record = $record->fresh() ?? $record;
        } catch (EInvoiceSecurityException $exception) {
            $securitySkipReason = $exception->reason ?? 'security_failed';
            Log::notice('e_invoice.security.skipped', [
                'workspace_id' => $document->workspaceId,
                'source_type' => $document->sourceType,
                'source_id' => $document->sourceId,
                'reason' => $securitySkipReason,
            ]);
        } catch (Throwable $exception) {
            $securitySkipReason = 'security_failed';
            Log::notice('e_invoice.security.skipped', [
                'workspace_id' => $document->workspaceId,
                'source_type' => $document->sourceType,
                'source_id' => $document->sourceId,
                'reason' => $securitySkipReason,
            ]);
        }

        $qr = null;
        $qrAvailable = false;
        $qrSkipReason = $securityGenerated ? null : 'security_unavailable';

        if ($securityGenerated) {
            $securityRecord = EInvoiceSecurityRecord::withoutGlobalScopes()
                ->where('e_invoice_document_id', $record->id)
                ->first();

            if ($securityRecord === null) {
                $qrSkipReason = 'security_unavailable';
            } else {
                try {
                    $qr = $this->qrService->generateFromSecurityRecord($document, $securityRecord);
                    $qrAvailable = true;
                } catch (QrEncodingException $exception) {
                    $qrSkipReason = $exception->reason ?? 'qr_failed';
                    Log::notice('e_invoice.qr.skipped', [
                        'workspace_id' => $document->workspaceId,
                        'source_type' => $document->sourceType,
                        'source_id' => $document->sourceId,
                        'reason' => $qrSkipReason,
                    ]);
                }
            }
        }

        return new InvoiceIssueResult(
            snapshot: $snapshot,
            record: $record->fresh() ?? $record,
            document: $document,
            reusedDocument: $reused,
            xmlAvailable: true,
            securityGenerated: $securityGenerated,
            qrAvailable: $qrAvailable,
            xml: $xml,
            qr: $qr,
            xmlSkipReason: null,
            securitySkipReason: $securitySkipReason,
            qrSkipReason: $qrSkipReason,
        );
    }

    public function requestProductionCryptographicStamp(): never
    {
        throw new ProductionCryptoUnavailableException;
    }

    private function isXmlEligible(EInvoiceDocument $document): bool
    {
        $kind = $document->kind();

        if ($kind === ElectronicDocumentKind::PurchaseInvoice
            || $kind === ElectronicDocumentKind::PosCashierInvoice) {
            return false;
        }

        return $document->typeCode() !== null && $document->transactionCode() !== null;
    }

    private function xmlIneligibilityReason(EInvoiceDocument $document): string
    {
        return match ($document->kind()) {
            ElectronicDocumentKind::PurchaseInvoice => 'purchase_not_applicable',
            ElectronicDocumentKind::PosCashierInvoice => 'pos_type_code_unspecified',
            default => 'type_or_transaction_code_missing',
        };
    }
}
