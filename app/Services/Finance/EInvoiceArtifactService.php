<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Services\EInvoicing\ComplianceReadinessService;

/**
 * Safe e-invoice artifact metadata for Web/API/Flutter.
 *
 * Does not expose certificates, private keys, or stamp material.
 * Does not claim FATOORA clearance or production signing.
 */
class EInvoiceArtifactService
{
    public function __construct(
        private readonly ComplianceReadinessService $complianceReadiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function availabilityForInvoice(FinanceInvoice $invoice): array
    {
        $assessment = $this->complianceReadiness->assessFinanceInvoice($invoice);

        return $assessment->toFinanceInvoiceArtifactArray(
            zatcaRequirement: $invoice->zatca_requirement,
            taxDocumentSubtype: $invoice->tax_document_subtype,
        );
    }
}
