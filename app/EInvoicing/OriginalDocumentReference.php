<?php

namespace App\EInvoicing;

final readonly class OriginalDocumentReference
{
    public function __construct(
        public ?int $invoiceId,
        public ?string $invoiceNumber,
        public ?string $invoiceIssueDate,
        public ?string $invoiceType,
    ) {}

    /**
     * @param  array<string, mixed>|null  $reference
     */
    public static function fromSnapshot(?array $reference): ?self
    {
        if ($reference === null || $reference === []) {
            return null;
        }

        $number = $reference['invoice_number'] ?? null;
        $id = $reference['invoice_id'] ?? null;
        if ($number === null && $id === null) {
            return null;
        }

        return new self(
            invoiceId: $id !== null ? (int) $id : null,
            invoiceNumber: $number !== null ? (string) $number : null,
            invoiceIssueDate: isset($reference['invoice_issue_date']) ? (string) $reference['invoice_issue_date'] : null,
            invoiceType: isset($reference['invoice_type']) ? (string) $reference['invoice_type'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'invoice_issue_date' => $this->invoiceIssueDate,
            'invoice_type' => $this->invoiceType,
        ];
    }
}
