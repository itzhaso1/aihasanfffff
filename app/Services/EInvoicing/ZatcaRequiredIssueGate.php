<?php

namespace App\Services\EInvoicing;

use App\Exceptions\Finance\ZatcaRequiredIssueBlockedException;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\IssuedDocumentSnapshot;

/**
 * Fail-closed compliance gate for {@code zatca_requirement = required} only.
 */
final class ZatcaRequiredIssueGate
{
    public function __construct(
        private readonly ComplianceReadinessService $complianceReadiness,
        private readonly EInvoiceFactory $factory,
    ) {}

    public function isRequired(FinanceInvoice $invoice): bool
    {
        if ((string) $invoice->type !== 'sales') {
            return false;
        }

        return (string) ($invoice->zatca_requirement ?? 'not_required') === 'required';
    }

    public function isRequiredForCreditNoteParent(?FinanceInvoice $invoice): bool
    {
        return $invoice !== null && $this->isRequired($invoice);
    }

    /**
     * Draft-phase checks (seller/buyer live data) before status transition.
     */
    public function assertDraftFinanceInvoice(FinanceInvoice $invoice): void
    {
        if (! $this->isRequired($invoice)) {
            return;
        }

        $assessment = $this->complianceReadiness->assessFinanceInvoice($invoice->fresh(['customer', 'items']));
        if ($assessment->seller->ready && $assessment->buyer->ready) {
            return;
        }

        throw new ZatcaRequiredIssueBlockedException(
            'لا يمكن إصدار الفاتورة: بيانات البائع أو المشتري غير مكتملة لمتطلبات الفوترة الإلكترونية.',
            $assessment,
        );
    }

    /**
     * Post-snapshot XML + XSD (+ configured Schematron) gate inside the issue transaction.
     */
    public function assertIssuedSnapshot(IssuedDocumentSnapshot $snapshot, bool $zatcaRequired): void
    {
        if (! $zatcaRequired) {
            return;
        }

        $this->factory->persist($snapshot);
        $assessment = $this->complianceReadiness->assessIssuedSnapshotForZatcaGate($snapshot);

        if ($assessment->passesRequiredZatcaIssueGate()) {
            return;
        }

        $message = match ($assessment->reason) {
            'xml_mapping_incomplete' => 'لا يمكن إصدار المستند: تعذر توليد XML من اللقطة.',
            'seller_data_incomplete' => 'لا يمكن إصدار المستند: بيانات البائع غير مكتملة.',
            'buyer_data_incomplete' => 'لا يمكن إصدار المستند: بيانات المشتري غير مكتملة.',
            default => $this->messageFromValidation($assessment),
        };

        throw new ZatcaRequiredIssueBlockedException($message, $assessment);
    }

    /**
     * Phase 2D.1: compliance validation passed earlier, but the mandatory ICV/PIH/hash
     * security chain must complete before the issue transaction commits.
     *
     * QR is not part of this gate (derived artifact; failures are logged, not blocking).
     */
    public function assertMandatoryIssuePipeline(
        InvoiceIssueResult $result,
        IssuedDocumentSnapshot $snapshot,
        bool $zatcaRequired,
        ?FinanceInvoice $financeInvoice = null,
        ?FinanceCreditNote $creditNote = null,
    ): void {
        if (! $zatcaRequired) {
            return;
        }

        if ($result->xmlAvailable && $result->securityGenerated && $this->securityRecordIsUsable($result->record)) {
            return;
        }

        $assessment = match (true) {
            $financeInvoice !== null => $this->complianceReadiness->assessFinanceInvoice($financeInvoice->fresh(['customer', 'items'])),
            $creditNote !== null => $this->complianceReadiness->assessFinanceCreditNote($creditNote->fresh(['items', 'invoice'])),
            default => $this->complianceReadiness->assessIssuedSnapshotForZatcaGate($snapshot),
        };

        $message = match (true) {
            ! $result->xmlAvailable => 'لا يمكن إصدار المستند: تعذر تجهيز XML بعد اجتياز التحقق.',
            ! $result->securityGenerated => 'لا يمكن إصدار المستند: سلسلة الأمان (ICV/PIH/التجزئة) لم تكتمل.',
            default => 'لا يمكن إصدار المستند: سجل الأمان غير صالح.',
        };

        throw new ZatcaRequiredIssueBlockedException($message, $assessment);
    }

    private function securityRecordIsUsable(\App\Models\EInvoicing\EInvoiceDocumentRecord $record): bool
    {
        $security = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $record->id)
            ->first();

        return $security !== null && trim((string) ($security->invoice_hash ?? '')) !== '';
    }

    private function messageFromValidation(ComplianceAssessment $assessment): string
    {
        $validation = $assessment->validation;
        if ($validation === null) {
            return 'لا يمكن إصدار المستند: متطلبات الامتثال للفوترة الإلكترونية غير مستوفاة.';
        }

        if (! $validation->wellFormed) {
            return 'لا يمكن إصدار المستند: XML غير سليم.';
        }

        return match ($validation->xsd->status) {
            \App\Enums\EInvoicing\ValidationChannelStatus::Invalid => 'لا يمكن إصدار المستند: فشل التحقق من XSD.',
            \App\Enums\EInvoicing\ValidationChannelStatus::NotAvailable => 'لا يمكن إصدار المستند: مخطط XSD غير متوفر في البيئة.',
            default => $validation->schematron->status === \App\Enums\EInvoicing\ValidationChannelStatus::Invalid
                ? 'لا يمكن إصدار المستند: فشل التحقق Schematron.'
                : 'لا يمكن إصدار المستند: متطلبات الامتثال للفوترة الإلكترونية غير مستوفاة.',
        };
    }
}
