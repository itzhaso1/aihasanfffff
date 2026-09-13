<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceDetailsDto
{
    /**
     * @param  list<InvoiceLineDto>  $lines
     */
    public function __construct(
        public int $id,
        public string $sourceType,
        public string $documentNumber,
        public string $documentType,
        public ?string $documentUuid,
        public ?string $issueDate,
        public ?string $issuedAt,
        public ?string $dueDate,
        public ?string $supplyDate,
        public string $currency,
        public InvoicePartyDto $seller,
        public InvoicePartyDto $buyer,
        public array $lines,
        public InvoiceTaxSummaryDto $tax,
        public InvoiceTotalsDto $totals,
        public InvoiceComplianceDto $compliance,
        public InvoiceReferencesDto $references,
        public ?string $notes,
        public ?string $paymentMethod,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->sourceType,
            'document_number' => $this->documentNumber,
            'document_type' => $this->documentType,
            'document_uuid' => $this->documentUuid,
            'issue_date' => $this->issueDate,
            'issued_at' => $this->issuedAt,
            'due_date' => $this->dueDate,
            'supply_date' => $this->supplyDate,
            'currency' => $this->currency,
            'seller' => $this->seller->toArray(),
            'buyer' => $this->buyer->toArray(),
            'lines' => array_map(
                static fn (InvoiceLineDto $line): array => $line->toArray(),
                $this->lines,
            ),
            'tax' => $this->tax->toArray(),
            'totals' => $this->totals->toArray(),
            'compliance' => $this->compliance->toArray(),
            'references' => $this->references->toArray(),
            'notes' => $this->notes,
            'payment' => [
                'method' => $this->paymentMethod,
            ],
        ];
    }
}
