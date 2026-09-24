<?php

namespace App\Services\EInvoicing;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\QR\QrEncodingException;
use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\PosCashierInvoice;
use App\Services\EInvoicing\QR\EInvoiceQrService;

/**
 * Authoritative read-only assessment of e-invoice artifact readiness.
 *
 * Does not mutate invoices, snapshots, e-invoice records, security, or QR state.
 * Does not persist generated XML.
 */
final class ComplianceReadinessService
{
    public function __construct(
        private readonly EInvoiceFactory $factory,
        private readonly EInvoiceXmlGenerator $xmlGenerator,
        private readonly EInvoiceQrService $qrService,
        private readonly KsaEInvoiceDataReadinessEvaluator $dataReadiness,
        private readonly FinanceInvoicePartyResolver $partyResolver,
        private readonly EInvoiceXmlValidationService $xmlValidation,
    ) {}

    public function assessFinanceInvoice(FinanceInvoice $invoice): ComplianceAssessment
    {
        $snapshot = $this->findFinanceInvoiceSnapshot($invoice);

        return $this->assess(
            snapshot: $snapshot,
            issued: $invoice->isIssued(),
            purchase: (string) $invoice->type === 'purchase',
            zatcaRequired: $this->isZatcaRequired($invoice->zatca_requirement),
            financeInvoice: $invoice,
        );
    }

    public function assessSnapshot(IssuedDocumentSnapshot $snapshot): ComplianceAssessment
    {
        return $this->assessIssuedSnapshot($snapshot, zatcaRequired: false);
    }

    public function assessIssuedSnapshotForZatcaGate(IssuedDocumentSnapshot $snapshot): ComplianceAssessment
    {
        return $this->assessIssuedSnapshot($snapshot, zatcaRequired: true);
    }

    private function assessIssuedSnapshot(IssuedDocumentSnapshot $snapshot, bool $zatcaRequired): ComplianceAssessment
    {
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $documentSection = is_array($payload['document'] ?? null) ? $payload['document'] : [];

        $purchase = ($documentSection['type'] ?? null) === 'purchase';
        $issued = $this->snapshotReflectsIssuedDocument($documentSection, (string) $snapshot->source_type);

        return $this->assess(
            snapshot: $snapshot,
            issued: $issued,
            purchase: $purchase,
            zatcaRequired: $zatcaRequired,
        );
    }

    public function assessFinanceCreditNote(FinanceCreditNote $note): ComplianceAssessment
    {
        $sourceType = $note->isCredit()
            ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
            : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE;

        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $note->workspace_id)
            ->where('source_type', $sourceType)
            ->where('source_id', $note->id)
            ->first();

        $zatcaRequired = $note->invoice !== null
            && $this->isZatcaRequired($note->invoice->zatca_requirement);

        return $this->assess(
            snapshot: $snapshot,
            issued: (string) $note->status === FinanceCreditNote::STATUS_ISSUED,
            purchase: false,
            zatcaRequired: $zatcaRequired,
        );
    }

    public function assessPosCashierInvoice(PosCashierInvoice $invoice): ComplianceAssessment
    {
        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE)
            ->where('source_id', $invoice->id)
            ->first();

        return $this->assess(
            snapshot: $snapshot,
            issued: (string) $invoice->status === 'closed',
            purchase: false,
            zatcaRequired: false,
        );
    }

    public function assessWorkspaceSeller(int $workspaceId): PartyDataReadiness
    {
        return $this->dataReadiness->evaluateWorkspaceSeller(
            $this->partyResolver->sellerFromWorkspace($workspaceId),
        );
    }

    public function isXmlEligible(EInvoiceDocument $document): bool
    {
        $kind = $document->kind();

        if ($kind === ElectronicDocumentKind::PurchaseInvoice
            || $kind === ElectronicDocumentKind::PosCashierInvoice) {
            return false;
        }

        return $document->typeCode() !== null && $document->transactionCode() !== null;
    }

    private function assess(
        ?IssuedDocumentSnapshot $snapshot,
        bool $issued,
        bool $purchase,
        bool $zatcaRequired,
        ?FinanceInvoice $financeInvoice = null,
    ): ComplianceAssessment {
        $na = PartyDataReadiness::notApplicable();

        if ($purchase) {
            return $this->buildAssessment(
                eligible: false,
                xmlAvailable: false,
                qrAvailable: false,
                securityAvailable: false,
                snapshotAvailable: $snapshot !== null,
                issued: $issued,
                purchase: true,
                zatcaRequired: $zatcaRequired,
                seller: $na,
                buyer: $na,
                reason: 'purchase_not_applicable',
                dataSource: $issued && $snapshot !== null ? 'snapshot' : null,
                documentRecord: $snapshot ? $this->recordForSnapshot($snapshot) : null,
            );
        }

        if (! $issued) {
            if ($financeInvoice !== null && (string) $financeInvoice->type === 'sales') {
                $draft = $this->partyResolver->partiesForDraftFinanceInvoice($financeInvoice);
                $seller = $this->dataReadiness->evaluateSeller($draft->seller);
                $buyer = $this->dataReadiness->evaluateBuyer($draft->buyer, $draft->isStandard);
                $pair = new KsaDataReadinessPair($seller, $buyer);

                return $this->buildAssessment(
                    eligible: false,
                    xmlAvailable: false,
                    qrAvailable: false,
                    securityAvailable: false,
                    snapshotAvailable: $snapshot !== null,
                    issued: false,
                    purchase: false,
                    zatcaRequired: $zatcaRequired,
                    seller: $seller,
                    buyer: $buyer,
                    pair: $pair,
                    reason: 'not_issued',
                    dataSource: 'live',
                    documentRecord: $snapshot ? $this->recordForSnapshot($snapshot) : null,
                );
            }

            return $this->buildAssessment(
                eligible: false,
                xmlAvailable: false,
                qrAvailable: false,
                securityAvailable: false,
                snapshotAvailable: $snapshot !== null,
                issued: false,
                purchase: false,
                zatcaRequired: $zatcaRequired,
                seller: $na,
                buyer: $na,
                reason: 'not_issued',
                documentRecord: $snapshot ? $this->recordForSnapshot($snapshot) : null,
            );
        }

        if ($snapshot === null) {
            return $this->buildAssessment(
                eligible: false,
                xmlAvailable: false,
                qrAvailable: false,
                securityAvailable: false,
                snapshotAvailable: false,
                issued: true,
                purchase: false,
                zatcaRequired: $zatcaRequired,
                seller: $na,
                buyer: $na,
                reason: 'no_snapshot',
            );
        }

        $record = $this->recordForSnapshot($snapshot);
        $security = $this->securityForRecord($snapshot, $record);
        $securityAvailable = $this->securityIsUsable($security);

        $document = $this->factory->make($snapshot);
        $dataPair = $this->dataReadiness->evaluateDocument($document);
        $eligible = $this->isXmlEligible($document);

        if (! $eligible) {
            $reason = $this->ineligibilityReason($document);
            $warnings = [];
            if ($zatcaRequired) {
                $warnings[] = 'E-invoice XML is required for this document but it is not eligible for generation.';
            }

            return $this->buildAssessment(
                eligible: false,
                xmlAvailable: false,
                qrAvailable: false,
                securityAvailable: $securityAvailable,
                snapshotAvailable: true,
                issued: true,
                purchase: false,
                zatcaRequired: $zatcaRequired,
                seller: $dataPair->seller,
                buyer: $dataPair->buyer,
                pair: $dataPair,
                warnings: $warnings,
                reason: $reason,
                dataSource: 'snapshot',
                documentRecord: $record,
            );
        }

        $xmlAvailable = false;
        $reason = null;
        $mappingIssues = [];
        $validation = null;
        $generatedXml = null;

        try {
            $generatedXml = $this->xmlGenerator->generate($document);
            $xmlAvailable = true;
        } catch (EInvoiceXmlMappingException $exception) {
            $reason = 'xml_mapping_incomplete';
            $mappingIssues[] = $this->issueFromMappingException($exception);
        }

        if ($generatedXml !== null) {
            $validation = $this->xmlValidation->assessXml($generatedXml->xml, 'snapshot');
            if ($record !== null) {
                $record = $this->xmlValidation->persistIssuedValidation($record, $validation);
            }
        }

        $extraWarnings = [];
        if (! $xmlAvailable && $zatcaRequired) {
            $extraWarnings[] = 'ZATCA e-invoice is marked required but XML could not be generated from the issued snapshot.';
        }

        $qrAvailable = false;
        $qrErrors = [];
        if ($xmlAvailable && $securityAvailable && $security !== null) {
            try {
                $this->qrService->generateFromSecurityRecord($document, $security);
                $qrAvailable = true;
            } catch (QrEncodingException $exception) {
                $reason = $reason ?? 'qr_unavailable';
                $qrErrors[] = new ComplianceIssue('qr_unavailable', 'qr', $this->publicQrError($exception));
            }
        } elseif ($xmlAvailable && ! $securityAvailable) {
            $reason = $reason ?? 'security_chain_pending';
        }

        if (! $dataPair->seller->ready && $reason === null) {
            $reason = 'seller_data_incomplete';
        } elseif (! $dataPair->buyer->ready && $reason === null) {
            $reason = 'buyer_data_incomplete';
        }

        return $this->buildAssessment(
            eligible: true,
            xmlAvailable: $xmlAvailable,
            qrAvailable: $qrAvailable,
            securityAvailable: $securityAvailable,
            snapshotAvailable: true,
            issued: true,
            purchase: false,
            zatcaRequired: $zatcaRequired,
            seller: $dataPair->seller,
            buyer: $dataPair->buyer,
            pair: $dataPair,
            extraIssues: [...$mappingIssues, ...$qrErrors],
            warnings: $extraWarnings,
            reason: $reason,
            dataSource: 'snapshot',
            documentRecord: $record,
            validation: $validation,
        );
    }

    /**
     * @param  list<string>  $warnings
     * @param  list<ComplianceIssue>  $extraIssues
     */
    private function buildAssessment(
        bool $eligible,
        bool $xmlAvailable,
        bool $qrAvailable,
        bool $securityAvailable,
        bool $snapshotAvailable,
        bool $issued,
        bool $purchase,
        bool $zatcaRequired,
        PartyDataReadiness $seller,
        PartyDataReadiness $buyer,
        ?KsaDataReadinessPair $pair = null,
        array $extraIssues = [],
        array $warnings = [],
        ?string $reason = null,
        ?string $dataSource = null,
        ?EInvoiceDocumentRecord $documentRecord = null,
        ?XmlValidationAssessment $validation = null,
    ): ComplianceAssessment {
        $pair ??= new KsaDataReadinessPair($seller, $buyer);
        $issues = [...$pair->allIssues(), ...$extraIssues];
        $issueWarnings = $pair->allWarnings();

        if ($zatcaRequired && (! $seller->ready || ! $buyer->ready)) {
            $warnings[] = 'ZATCA e-invoice is marked required but seller/buyer data is incomplete for the current XML mapper.';
        }

        $stringErrors = array_map(static fn (ComplianceIssue $issue): string => $issue->message, $issues);

        return new ComplianceAssessment(
            eligible: $eligible,
            xmlAvailable: $xmlAvailable,
            qrAvailable: $qrAvailable,
            securityAvailable: $securityAvailable,
            snapshotAvailable: $snapshotAvailable,
            issued: $issued,
            purchase: $purchase,
            zatcaRequired: $zatcaRequired,
            errors: $stringErrors,
            warnings: $warnings,
            seller: $seller,
            buyer: $buyer,
            issues: $issues,
            issueWarnings: $issueWarnings,
            reason: $reason,
            dataSource: $dataSource,
            documentRecord: $documentRecord,
            validation: $validation,
        );
    }

    private function issueFromMappingException(EInvoiceXmlMappingException $exception): ComplianceIssue
    {
        $field = trim((string) ($exception->fieldPath ?? ''));

        return new ComplianceIssue(
            code: 'xml_mapping_incomplete',
            field: $field !== '' ? $field : 'xml',
            message: $this->publicMappingError($exception),
            source: 'mapper',
        );
    }

    private function findFinanceInvoiceSnapshot(FinanceInvoice $invoice): ?IssuedDocumentSnapshot
    {
        return IssuedDocumentSnapshot::withoutGlobalScopes()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('source_type', IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE)
            ->where('source_id', $invoice->id)
            ->first();
    }

    private function recordForSnapshot(IssuedDocumentSnapshot $snapshot): ?EInvoiceDocumentRecord
    {
        return EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('issued_document_snapshot_id', $snapshot->id)
            ->first();
    }

    private function securityForRecord(
        IssuedDocumentSnapshot $snapshot,
        ?EInvoiceDocumentRecord $record,
    ): ?EInvoiceSecurityRecord {
        if ($record === null) {
            return null;
        }

        return EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('e_invoice_document_id', $record->id)
            ->first();
    }

    private function securityIsUsable(?EInvoiceSecurityRecord $security): bool
    {
        if ($security === null) {
            return false;
        }

        $hash = trim((string) ($security->invoice_hash ?? ''));

        return $hash !== '';
    }

    private function ineligibilityReason(EInvoiceDocument $document): string
    {
        return match ($document->kind()) {
            ElectronicDocumentKind::PurchaseInvoice => 'purchase_not_applicable',
            ElectronicDocumentKind::PosCashierInvoice => 'pos_type_code_unspecified',
            default => 'type_or_transaction_code_missing',
        };
    }

    /**
     * @param  array<string, mixed>  $documentSection
     */
    private function snapshotReflectsIssuedDocument(array $documentSection, string $sourceType): bool
    {
        if ($sourceType === IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE) {
            return true;
        }

        $status = (string) ($documentSection['status'] ?? '');

        return $status === 'issued' || $status === FinanceCreditNote::STATUS_ISSUED;
    }

    private function isZatcaRequired(mixed $requirement): bool
    {
        return (string) ($requirement ?? 'not_required') === 'required';
    }

    private function publicMappingError(EInvoiceXmlMappingException $exception): string
    {
        $field = $exception->fieldPath;
        $message = trim($exception->getMessage());

        if ($field !== null && $field !== '') {
            return $message !== ''
                ? $message.' ('.$field.')'
                : 'XML mapping incomplete ('.$field.').';
        }

        return $message !== '' ? $message : 'XML mapping incomplete.';
    }

    private function publicQrError(QrEncodingException $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'QR encoding failed.';
    }
}
