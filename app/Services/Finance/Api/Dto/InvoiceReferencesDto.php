<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceReferencesDto
{
    public function __construct(
        public ?int $originalInvoiceId,
        public ?string $originalInvoiceNumber,
        public ?string $originalInvoiceIssueDate,
        public ?string $originalInvoiceType,
        public ?string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'original_invoice_id' => $this->originalInvoiceId,
            'original_invoice_number' => $this->originalInvoiceNumber,
            'original_invoice_issue_date' => $this->originalInvoiceIssueDate,
            'original_invoice_type' => $this->originalInvoiceType,
            'reason' => $this->reason,
        ];
    }
}
