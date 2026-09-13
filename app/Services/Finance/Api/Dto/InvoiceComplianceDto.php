<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceComplianceDto
{
    public function __construct(
        public ?string $businessStatus,
        public ?string $paymentStatus,
        public ?string $complianceStatus,
        public ?string $documentKind,
        public ?string $invoiceType,
        public ?string $transactionCode,
        public bool $xmlAvailable,
        public bool $qrAvailable,
        public ?string $securityStatus,
        public bool $productionStampingAvailable = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'business_status' => $this->businessStatus,
            'payment_status' => $this->paymentStatus,
            'compliance_status' => $this->complianceStatus,
            'document_kind' => $this->documentKind,
            'invoice_type' => $this->invoiceType,
            'transaction_code' => $this->transactionCode,
            'xml_available' => $this->xmlAvailable,
            'qr_available' => $this->qrAvailable,
            'security_status' => $this->securityStatus,
            'production_stamping_available' => false,
        ];
    }
}
