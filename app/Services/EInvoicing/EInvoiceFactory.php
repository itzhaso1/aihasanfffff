<?php

namespace App\Services\EInvoicing;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\EInvoiceLine;
use App\EInvoicing\EInvoiceParty;
use App\EInvoicing\EInvoiceTax;
use App\EInvoicing\EInvoiceTotals;
use App\EInvoicing\InvoiceTypeCatalog;
use App\EInvoicing\OriginalDocumentReference;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Support\Tenancy\WorkspaceContext;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Maps an immutable issued snapshot into an electronic-invoice document.
 *
 * This factory must not query live Finance/POS/customer/product/workspace
 * settings tables. It reads only the snapshot already in memory.
 */
class EInvoiceFactory
{
    public function make(IssuedDocumentSnapshot $snapshot): EInvoiceDocument
    {
        $this->assertSupportedSource($snapshot);
        $this->assertWorkspaceIsolation($snapshot);

        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $invoiceType = InvoiceTypeCatalog::fromSnapshot($snapshot);
        $compliance = $invoiceType->kind === ElectronicDocumentKind::PurchaseInvoice
            ? ComplianceStatus::NotApplicable
            : ComplianceStatus::Ready;

        return new EInvoiceDocument(
            workspaceId: (int) $snapshot->workspace_id,
            sourceSnapshotId: (int) $snapshot->id,
            sourceType: (string) $snapshot->source_type,
            sourceId: (int) $snapshot->source_id,
            invoiceType: $invoiceType,
            documentNumber: (string) ($document['number'] ?? $snapshot->document_number ?? ''),
            issueDate: $this->nullableString($document['issue_date'] ?? $snapshot->issue_date),
            issuedAt: $this->nullableString($document['issued_at'] ?? $snapshot->issued_at),
            currency: (string) ($document['currency'] ?? $snapshot->currency ?? 'SAR'),
            seller: EInvoiceParty::fromSnapshot(is_array($payload['seller'] ?? null) ? $payload['seller'] : []),
            buyer: EInvoiceParty::fromSnapshot(is_array($payload['buyer'] ?? null) ? $payload['buyer'] : []),
            lines: $this->mapLines(is_array($payload['lines'] ?? null) ? $payload['lines'] : []),
            tax: EInvoiceTax::fromSnapshot(is_array($payload['tax'] ?? null) ? $payload['tax'] : []),
            totals: EInvoiceTotals::fromSnapshot(is_array($payload['totals'] ?? null) ? $payload['totals'] : []),
            originalDocument: OriginalDocumentReference::fromSnapshot(
                is_array($payload['reference'] ?? null) ? $payload['reference'] : null
            ),
            businessStatus: $this->nullableString($document['status'] ?? null),
            paymentStatus: $this->nullableString($payment['payment_status'] ?? null),
            complianceStatus: $compliance,
            payment: $payment,
            sourceMetadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            reason: $this->nullableString($document['reason'] ?? null),
            notes: $this->nullableString($document['notes'] ?? null),
            supplyDate: $this->nullableDate($document['supply_date'] ?? null),
        );
    }

    public function persist(IssuedDocumentSnapshot $snapshot): EInvoiceDocumentRecord
    {
        $document = $this->make($snapshot);

        $existing = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('issued_document_snapshot_id', $document->sourceSnapshotId)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return EInvoiceDocumentRecord::withoutGlobalScopes()->create([
                'workspace_id' => $document->workspaceId,
                'issued_document_snapshot_id' => $document->sourceSnapshotId,
                'source_type' => $document->sourceType,
                'source_id' => $document->sourceId,
                'document_kind' => $document->kind()->value,
                'type_code' => $document->typeCode()?->value,
                'transaction_code' => $document->transactionCode()?->value,
                'document_number' => $document->documentNumber,
                'issue_date' => $document->issueDate,
                'issued_at' => $document->issuedAt,
                'currency' => $document->currency,
                'compliance_status' => $document->complianceStatus->value,
                'payload' => $document->toArray(),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = EInvoiceDocumentRecord::withoutGlobalScopes()
                ->where('issued_document_snapshot_id', $document->sourceSnapshotId)
                ->first();
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function assertSupportedSource(IssuedDocumentSnapshot $snapshot): void
    {
        $sourceType = (string) $snapshot->source_type;

        if (! in_array($sourceType, InvoiceTypeCatalog::supportedSourceTypes(), true)) {
            throw new InvalidArgumentException(
                "Unsupported issued snapshot source [{$sourceType}] for electronic invoicing."
            );
        }
    }

    private function assertWorkspaceIsolation(IssuedDocumentSnapshot $snapshot): void
    {
        $currentWorkspaceId = app(WorkspaceContext::class)->workspaceId();

        if ($currentWorkspaceId === null) {
            return;
        }

        if ((int) $currentWorkspaceId !== (int) $snapshot->workspace_id) {
            throw new InvalidArgumentException(
                'Electronic invoice documents must stay in the snapshot workspace.'
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<EInvoiceLine>
     */
    private function mapLines(array $lines): array
    {
        return array_values(array_map(
            static fn (array $line): EInvoiceLine => EInvoiceLine::fromSnapshot($line),
            $lines,
        ));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $date = (string) $value;

        return $date === '' ? null : substr($date, 0, 10);
    }
}
