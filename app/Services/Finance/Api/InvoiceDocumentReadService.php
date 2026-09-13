<?php

namespace App\Services\Finance\Api;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Exceptions\Api\ComplianceUnavailableException;
use App\Exceptions\Api\ProductionCryptoUnavailableException;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\PosCashierInvoice;
use App\Models\Workspace;
use App\Services\EInvoicing\EInvoiceFactory;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\EInvoicing\InvoiceIssueService;
use App\Services\EInvoicing\QR\EInvoiceQrService;
use App\Services\Finance\Api\Dto\InvoiceDetailsDto;
use App\Services\Finance\Api\Dto\InvoiceQrDto;
use App\Services\Finance\Api\Dto\InvoiceSummaryDto;
use App\Services\Finance\Api\Dto\InvoiceXmlDto;
use App\Services\Finance\PdfInvoiceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

final class InvoiceDocumentReadService
{
    public function __construct(
        private readonly EInvoiceFactory $factory,
        private readonly EInvoiceXmlGenerator $xmlGenerator,
        private readonly EInvoiceQrService $qrService,
        private readonly InvoiceIssueService $invoiceIssueService,
        private readonly InvoiceApiAssembler $assembler,
        private readonly PdfInvoiceService $pdfInvoiceService,
    ) {}

    /**
     * @return LengthAwarePaginator<int, InvoiceSummaryDto>
     */
    public function listFinanceInvoices(Workspace $workspace, int $perPage = 25): LengthAwarePaginator
    {
        $page = FinanceInvoice::query()
            ->with(['customer', 'supplier'])
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->paginate($perPage);

        $documents = $this->documentsFor($workspace, IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE, $page->getCollection()->pluck('id'));

        $page->setCollection(
            $page->getCollection()->map(function (FinanceInvoice $invoice) use ($documents): InvoiceSummaryDto {
                [$document, $record] = $documents[(int) $invoice->id] ?? [null, null];

                return $this->assembler->summaryFromFinance($invoice, $document, $record);
            })
        );

        return $page;
    }

    /**
     * @return LengthAwarePaginator<int, InvoiceSummaryDto>
     */
    public function listNotes(Workspace $workspace, int $perPage = 25): LengthAwarePaginator
    {
        $page = FinanceCreditNote::query()
            ->with(['customer', 'invoice'])
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->paginate($perPage);

        $creditIds = $page->getCollection()->filter(fn (FinanceCreditNote $note) => $note->isCredit())->pluck('id');
        $debitIds = $page->getCollection()->filter(fn (FinanceCreditNote $note) => ! $note->isCredit())->pluck('id');
        $creditDocs = $this->documentsFor($workspace, IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE, $creditIds);
        $debitDocs = $this->documentsFor($workspace, IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE, $debitIds);

        $page->setCollection(
            $page->getCollection()->map(function (FinanceCreditNote $note) use ($creditDocs, $debitDocs): InvoiceSummaryDto {
                $map = $note->isCredit() ? $creditDocs : $debitDocs;
                [$document, $record] = $map[(int) $note->id] ?? [null, null];

                return $this->assembler->summaryFromNote($note, $document, $record);
            })
        );

        return $page;
    }

    /**
     * @return LengthAwarePaginator<int, InvoiceSummaryDto>
     */
    public function listPosInvoices(Workspace $workspace, int $perPage = 25): LengthAwarePaginator
    {
        $page = PosCashierInvoice::query()
            ->with(['orders.customer'])
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->paginate($perPage);

        $documents = $this->documentsFor($workspace, IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE, $page->getCollection()->pluck('id'));

        $page->setCollection(
            $page->getCollection()->map(function (PosCashierInvoice $invoice) use ($documents): InvoiceSummaryDto {
                [$document, $record] = $documents[(int) $invoice->id] ?? [null, null];

                return $this->assembler->summaryFromPos($invoice, $document, $record);
            })
        );

        return $page;
    }

    public function financeDetails(FinanceInvoice $invoice): InvoiceDetailsDto
    {
        $snapshot = $this->snapshot(IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE, (int) $invoice->id);
        if ($snapshot === null) {
            return $this->assembler->draftFinanceDetails($invoice);
        }

        return $this->detailsFromSnapshot(
            (int) $invoice->id,
            (string) $invoice->type,
            $snapshot,
            $invoice->due_date?->toDateString(),
            is_string($invoice->payment_terms) ? $invoice->payment_terms : null,
        );
    }

    public function noteDetails(FinanceCreditNote $note): InvoiceDetailsDto
    {
        $sourceType = $note->isCredit()
            ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
            : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE;
        $snapshot = $this->snapshot($sourceType, (int) $note->id);
        if ($snapshot === null) {
            throw new ComplianceUnavailableException('The note has not been issued yet.');
        }

        return $this->detailsFromSnapshot((int) $note->id, (string) $note->type, $snapshot);
    }

    public function posDetails(PosCashierInvoice $invoice): InvoiceDetailsDto
    {
        $snapshot = $this->snapshot(IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE, (int) $invoice->id);
        if ($snapshot === null) {
            throw new ComplianceUnavailableException('The POS invoice has no issued snapshot.');
        }

        $paymentMethod = $invoice->orders
            ->map(fn ($order) => data_get($order->metadata, 'payment_method'))
            ->filter()
            ->unique()
            ->values()
            ->first();

        return $this->detailsFromSnapshot(
            (int) $invoice->id,
            'pos',
            $snapshot,
            paymentMethod: is_string($paymentMethod) ? $paymentMethod : null,
        );
    }

    public function xmlForFinance(FinanceInvoice $invoice): InvoiceXmlDto
    {
        return $this->xml($this->requireSnapshot(IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE, (int) $invoice->id));
    }

    public function xmlForNote(FinanceCreditNote $note): InvoiceXmlDto
    {
        $sourceType = $note->isCredit()
            ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
            : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE;

        return $this->xml($this->requireSnapshot($sourceType, (int) $note->id));
    }

    public function xmlForPos(PosCashierInvoice $invoice): InvoiceXmlDto
    {
        return $this->xml($this->requireSnapshot(IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE, (int) $invoice->id));
    }

    public function qrForFinance(FinanceInvoice $invoice): InvoiceQrDto
    {
        return $this->qr($this->requireSnapshot(IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE, (int) $invoice->id));
    }

    public function qrForNote(FinanceCreditNote $note): InvoiceQrDto
    {
        $sourceType = $note->isCredit()
            ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
            : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE;

        return $this->qr($this->requireSnapshot($sourceType, (int) $note->id));
    }

    public function qrForPos(PosCashierInvoice $invoice): InvoiceQrDto
    {
        return $this->qr($this->requireSnapshot(IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE, (int) $invoice->id));
    }

    public function pdfForFinance(FinanceInvoice $invoice): Response|Responsable
    {
        return $this->pdfInvoiceService->download($invoice);
    }

    public function requestProductionStamp(): never
    {
        $this->invoiceIssueService->requestProductionCryptographicStamp();
    }

    private function xml(IssuedDocumentSnapshot $snapshot): InvoiceXmlDto
    {
        $document = $this->factory->make($snapshot);
        $this->assertXmlEligible($document);

        try {
            $generated = $this->xmlGenerator->generate($document);
        } catch (EInvoiceXmlMappingException $exception) {
            throw new ComplianceUnavailableException(
                'Electronic invoice XML is not available for this document.',
                $exception,
            );
        }

        return new InvoiceXmlDto(
            documentNumber: $generated->documentNumber,
            documentUuid: $generated->documentUuid,
            documentKind: $generated->documentKind->value,
            xml: $generated->xml,
            rootLocalName: $generated->rootLocalName,
            schemaValid: $generated->schemaValid,
        );
    }

    private function qr(IssuedDocumentSnapshot $snapshot): InvoiceQrDto
    {
        $document = $this->factory->make($snapshot);
        $this->assertXmlEligible($document);

        $record = $this->recordForSnapshot($snapshot);
        if ($record === null) {
            throw new ComplianceUnavailableException('Electronic invoice identity is not available for this document.');
        }

        $security = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('e_invoice_document_id', $record->id)
            ->first();

        if ($security === null) {
            throw new ComplianceUnavailableException('QR is not available until the security chain has been generated.');
        }

        $payload = $this->qrService->generateFromSecurityRecord($document, $security);
        if ($payload->includesCryptographicTags()) {
            throw new ProductionCryptoUnavailableException;
        }

        $tags = [];
        foreach ([QrTag::SELLER_NAME, QrTag::SELLER_VAT, QrTag::TIMESTAMP, QrTag::TOTAL_WITH_VAT, QrTag::VAT_TOTAL, QrTag::INVOICE_HASH] as $tag) {
            $value = $payload->valueForTag($tag);
            if ($value !== null) {
                $tags[$tag] = $value;
            }
        }

        return new InvoiceQrDto(
            base64: $payload->base64,
            profile: $payload->profile->value,
            tags: $tags,
        );
    }

    private function detailsFromSnapshot(
        int $id,
        string $documentType,
        IssuedDocumentSnapshot $snapshot,
        ?string $dueDate = null,
        ?string $paymentMethod = null,
    ): InvoiceDetailsDto {
        $document = $this->factory->make($snapshot);
        $record = $this->recordForSnapshot($snapshot);
        $security = $record === null ? null : EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $record->id)
            ->first();

        $xmlAvailable = false;
        if ($this->xmlEligible($document)) {
            try {
                $this->xmlGenerator->generate($document);
                $xmlAvailable = true;
            } catch (EInvoiceXmlMappingException) {
                $xmlAvailable = false;
            }
        }
        $qrAvailable = $xmlAvailable && $security !== null;

        return $this->assembler->detailsFromDocument(
            id: $id,
            fallbackType: $documentType,
            document: $document,
            record: $record,
            xmlAvailable: $xmlAvailable,
            qrAvailable: $qrAvailable,
            securityStatus: $this->assembler->securityStatus($security),
            dueDate: $dueDate,
            paymentMethod: $paymentMethod,
        );
    }

    /**
     * @param  Collection<int, int|string>  $sourceIds
     * @return array<int, array{0: EInvoiceDocument|null, 1: EInvoiceDocumentRecord|null}>
     */
    private function documentsFor(Workspace $workspace, string $sourceType, Collection $sourceIds): array
    {
        $ids = $sourceIds->map(fn ($id): int => (int) $id)->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $records = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $ids->all())
            ->get()
            ->keyBy(fn (EInvoiceDocumentRecord $record): int => (int) $record->source_id);

        $snapshots = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $ids->all())
            ->get()
            ->keyBy(fn (IssuedDocumentSnapshot $snapshot): int => (int) $snapshot->source_id);

        $map = [];
        foreach ($ids as $id) {
            $snapshot = $snapshots->get($id);
            $record = $records->get($id);
            $document = $snapshot instanceof IssuedDocumentSnapshot
                ? $this->factory->make($snapshot)
                : null;
            $map[$id] = [$document, $record];
        }

        return $map;
    }

    private function snapshot(string $sourceType, int $sourceId): ?IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    private function requireSnapshot(string $sourceType, int $sourceId): IssuedDocumentSnapshot
    {
        $snapshot = $this->snapshot($sourceType, $sourceId);
        if ($snapshot === null) {
            throw new ComplianceUnavailableException('An issued document snapshot is required.');
        }

        return $snapshot;
    }

    private function recordForSnapshot(IssuedDocumentSnapshot $snapshot): ?EInvoiceDocumentRecord
    {
        return EInvoiceDocumentRecord::query()
            ->where('issued_document_snapshot_id', $snapshot->id)
            ->first();
    }

    private function xmlEligible(EInvoiceDocument $document): bool
    {
        $kind = $document->kind();

        if ($kind === ElectronicDocumentKind::PurchaseInvoice
            || $kind === ElectronicDocumentKind::PosCashierInvoice) {
            return false;
        }

        return $document->typeCode() !== null && $document->transactionCode() !== null;
    }

    private function assertXmlEligible(EInvoiceDocument $document): void
    {
        if (! $this->xmlEligible($document)) {
            throw new ComplianceUnavailableException(
                'Electronic invoice XML is not available for this document kind.',
            );
        }
    }
}
