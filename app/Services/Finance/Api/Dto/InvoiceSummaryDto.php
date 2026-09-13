<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceSummaryDto
{
    public function __construct(
        public int $id,
        public string $sourceType,
        public string $documentNumber,
        public string $documentType,
        public ?string $documentUuid,
        public ?string $issueDate,
        public string $currency,
        public string $total,
        public string $taxAmount,
        public ?string $businessStatus,
        public ?string $paymentStatus,
        public ?string $complianceStatus,
        public ?string $counterpartyName,
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
            'currency' => $this->currency,
            'total' => $this->total,
            'tax_amount' => $this->taxAmount,
            'business_status' => $this->businessStatus,
            'payment_status' => $this->paymentStatus,
            'compliance_status' => $this->complianceStatus,
            'counterparty_name' => $this->counterpartyName,
        ];
    }
}
