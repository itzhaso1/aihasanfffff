<?php

namespace App\Services\EInvoicing;

use App\Models\EInvoicing\EInvoiceDocumentRecord;

/**
 * Read-only e-invoice compliance / artifact readiness for a single document context.
 */
final readonly class ComplianceAssessment
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  list<ComplianceIssue>  $issues
     * @param  list<ComplianceIssue>  $issueWarnings
     */
    public function __construct(
        public bool $eligible,
        public bool $xmlAvailable,
        public bool $qrAvailable,
        public bool $securityAvailable,
        public bool $snapshotAvailable,
        public bool $issued,
        public bool $purchase,
        public bool $zatcaRequired,
        public array $errors,
        public array $warnings,
        public PartyDataReadiness $seller,
        public PartyDataReadiness $buyer,
        public array $issues = [],
        public array $issueWarnings = [],
        public ?string $reason = null,
        public ?string $dataSource = null,
        public ?EInvoiceDocumentRecord $documentRecord = null,
        public ?XmlValidationAssessment $validation = null,
    ) {}

    /**
     * Legacy zatca artifact payload for Web/API/Flutter (Finance invoices).
     *
     * @return array<string, mixed>
     */
    public function toFinanceInvoiceArtifactArray(
        ?string $zatcaRequirement,
        ?string $taxDocumentSubtype,
    ): array {
        $record = $this->documentRecord;

        return [
            'requirement' => $zatcaRequirement,
            'tax_document_subtype' => $taxDocumentSubtype,
            'has_snapshot' => $this->snapshotAvailable,
            'has_document' => $record !== null,
            'has_xml' => $this->xmlAvailable,
            'has_qr' => $this->qrAvailable,
            'xml_available' => $this->xmlAvailable,
            'qr_available' => $this->qrAvailable,
            'clearance' => false,
            'reporting' => false,
            'production_stamp' => false,
            'integration' => 'foundation',
            'not_applicable' => $this->purchase,
            'reason' => $this->reason,
            'compliance' => $this->compliancePayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function compliancePayload(): array
    {
        return [
            'eligible' => $this->eligible,
            'ready' => $this->readyForRequiredZatca(),
            'xml_available' => $this->xmlAvailable,
            'qr_available' => $this->qrAvailable,
            'security_available' => $this->securityAvailable,
            'snapshot_available' => $this->snapshotAvailable,
            'issued' => $this->issued,
            'purchase' => $this->purchase,
            'zatca_required' => $this->zatcaRequired,
            'data_source' => $this->dataSource,
            'seller' => $this->seller->toArray(),
            'buyer' => $this->buyer->toArray(),
            'errors' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $this->issues),
            'warnings' => array_merge(
                $this->warnings,
                array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $this->issueWarnings),
            ),
            'validation' => $this->validation?->toArray(),
        ];
    }

    /**
     * When ZATCA is not required, issue/readiness is not blocked by this assessment.
     */
    public function readyForRequiredZatca(): bool
    {
        if (! $this->zatcaRequired || $this->purchase) {
            return true;
        }

        if (! $this->issued) {
            return $this->seller->ready && $this->buyer->ready;
        }

        return $this->passesRequiredZatcaIssueGate();
    }

    public function passesRequiredZatcaIssueGate(): bool
    {
        if (! $this->zatcaRequired || $this->purchase) {
            return true;
        }

        if (! $this->seller->ready || ! $this->buyer->ready) {
            return false;
        }

        if (! $this->eligible || ! $this->xmlAvailable) {
            return false;
        }

        if ($this->validation === null) {
            return false;
        }

        return $this->validation->passesRequiredIssueGate();
    }
}
