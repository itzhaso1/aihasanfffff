<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceComplianceDto
{
    /**
     * @param  list<array<string, string>>|null  $dataErrors
     * @param  list<string|array<string, string>>|null  $dataWarnings
     */
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
        public ?array $seller = null,
        public ?array $buyer = null,
        public ?array $dataErrors = null,
        public ?array $dataWarnings = null,
        public ?string $dataSource = null,
        public ?array $validation = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
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

        if ($this->seller !== null) {
            $payload['seller'] = $this->seller;
        }
        if ($this->buyer !== null) {
            $payload['buyer'] = $this->buyer;
        }
        if ($this->dataErrors !== null) {
            $payload['errors'] = $this->dataErrors;
        }
        if ($this->dataWarnings !== null) {
            $payload['warnings'] = $this->dataWarnings;
        }
        if ($this->dataSource !== null) {
            $payload['data_source'] = $this->dataSource;
        }
        if ($this->validation !== null) {
            $payload['validation'] = $this->validation;
        }

        return $payload;
    }
}
