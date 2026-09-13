<?php

namespace App\EInvoicing;

use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;

final readonly class InvoiceType
{
    public function __construct(
        public ElectronicDocumentKind $kind,
        public ?InvoiceTypeCode $typeCode,
        public ?InvoiceTransactionCode $transactionCode,
    ) {}

    public function isStandard(): bool
    {
        return $this->transactionCode?->isStandard() === true;
    }

    public function isSimplified(): bool
    {
        return $this->transactionCode?->isSimplified() === true
            || $this->kind === ElectronicDocumentKind::SimplifiedTaxInvoice
            || $this->kind === ElectronicDocumentKind::SimplifiedCreditNote
            || $this->kind === ElectronicDocumentKind::SimplifiedDebitNote;
    }

    public function isCredit(): bool
    {
        return $this->kind->isCredit();
    }

    public function isDebit(): bool
    {
        return $this->kind->isDebit();
    }

    public function isInvoice(): bool
    {
        return $this->kind->isSalesInvoice() || $this->kind === ElectronicDocumentKind::PosCashierInvoice;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'type_code' => $this->typeCode?->value,
            'transaction_code' => $this->transactionCode?->value,
            'is_standard' => $this->isStandard(),
            'is_simplified' => $this->isSimplified(),
            'is_credit' => $this->isCredit(),
            'is_debit' => $this->isDebit(),
        ];
    }
}
