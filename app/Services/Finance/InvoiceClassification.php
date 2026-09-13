<?php

namespace App\Services\Finance;

use App\Enums\Finance\TaxDocumentSubtype;
use App\Enums\Finance\ZatcaRequirement;
use App\Models\Finance\FinanceInvoice;

final class InvoiceClassification
{
    public function __construct(
        public readonly TaxDocumentSubtype $taxDocumentSubtype,
        public readonly ZatcaRequirement $zatcaRequirement,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, string $direction): self
    {
        $subtype = TaxDocumentSubtype::tryFrom((string) ($payload['tax_document_subtype'] ?? ''))
            ?? TaxDocumentSubtype::Standard;

        if ($direction === 'purchase') {
            return new self($subtype, ZatcaRequirement::NotRequired);
        }

        $requirement = ZatcaRequirement::tryFrom((string) ($payload['zatca_requirement'] ?? ''))
            ?? ZatcaRequirement::NotRequired;

        return new self($subtype, $requirement);
    }

    public static function forHistoricalInvoice(FinanceInvoice $invoice): self
    {
        $direction = (string) $invoice->type;
        $subtype = TaxDocumentSubtype::tryFrom((string) ($invoice->getAttributes()['tax_document_subtype'] ?? ''))
            ?? TaxDocumentSubtype::Standard;
        $requirement = $direction === 'purchase'
            ? ZatcaRequirement::NotRequired
            : (ZatcaRequirement::tryFrom((string) ($invoice->getAttributes()['zatca_requirement'] ?? '')) ?? ZatcaRequirement::NotRequired);

        return new self($subtype, $requirement);
    }
}
