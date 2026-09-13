<?php

namespace App\EInvoicing;

use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Models\Finance\IssuedDocumentSnapshot;

/**
 * In-memory electronic-invoice projection of an issued snapshot.
 *
 * This is not FinanceInvoice. It does not calculate tax, generate XML,
 * or contact any clearance service.
 */
final class EInvoiceDocument
{
    /**
     * @param  list<EInvoiceLine>  $lines
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $sourceSnapshotId,
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly InvoiceType $invoiceType,
        public readonly string $documentNumber,
        public readonly ?string $issueDate,
        public readonly ?string $issuedAt,
        public readonly string $currency,
        public readonly EInvoiceParty $seller,
        public readonly EInvoiceParty $buyer,
        public readonly array $lines,
        public readonly EInvoiceTax $tax,
        public readonly EInvoiceTotals $totals,
        public readonly ?OriginalDocumentReference $originalDocument,
        public readonly ?string $businessStatus,
        public readonly ?string $paymentStatus,
        public readonly ComplianceStatus $complianceStatus,
        public readonly array $payment,
        public readonly array $sourceMetadata,
        public readonly ?string $reason = null,
        public readonly ?string $notes = null,
        public readonly ?string $supplyDate = null,
    ) {}

    public function kind(): ElectronicDocumentKind
    {
        return $this->invoiceType->kind;
    }

    public function typeCode(): ?InvoiceTypeCode
    {
        return $this->invoiceType->typeCode;
    }

    public function transactionCode(): ?InvoiceTransactionCode
    {
        return $this->invoiceType->transactionCode;
    }

    public function isStandard(): bool
    {
        return $this->invoiceType->isStandard();
    }

    public function isSimplified(): bool
    {
        return $this->invoiceType->isSimplified();
    }

    public function snapshotIdentityMatches(IssuedDocumentSnapshot $snapshot): bool
    {
        return $this->sourceSnapshotId === (int) $snapshot->id
            && $this->workspaceId === (int) $snapshot->workspace_id
            && $this->sourceType === (string) $snapshot->source_type
            && $this->sourceId === (int) $snapshot->source_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'source_snapshot_id' => $this->sourceSnapshotId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'invoice_type' => $this->invoiceType->toArray(),
            'document_number' => $this->documentNumber,
            'issue_date' => $this->issueDate,
            'issued_at' => $this->issuedAt,
            'currency' => $this->currency,
            'seller' => $this->seller->toArray(),
            'buyer' => $this->buyer->toArray(),
            'lines' => array_map(
                static fn (EInvoiceLine $line): array => $line->toArray(),
                $this->lines,
            ),
            'tax' => $this->tax->toArray(),
            'totals' => $this->totals->toArray(),
            'original_document' => $this->originalDocument?->toArray(),
            'business_status' => $this->businessStatus,
            'payment_status' => $this->paymentStatus,
            'compliance_status' => $this->complianceStatus->value,
            'payment' => $this->payment,
            'source_metadata' => $this->sourceMetadata,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'supply_date' => $this->supplyDate,
        ];
    }
}
