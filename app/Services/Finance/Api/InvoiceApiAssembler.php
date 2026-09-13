<?php

namespace App\Services\Finance\Api;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\EInvoiceLine;
use App\EInvoicing\EInvoiceParty;
use App\EInvoicing\Xml\DocumentUuid;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\PosCashierInvoice;
use App\Services\Finance\Api\Dto\InvoiceComplianceDto;
use App\Services\Finance\Api\Dto\InvoiceDetailsDto;
use App\Services\Finance\Api\Dto\InvoiceLineDto;
use App\Services\Finance\Api\Dto\InvoicePartyDto;
use App\Services\Finance\Api\Dto\InvoiceReferencesDto;
use App\Services\Finance\Api\Dto\InvoiceSummaryDto;
use App\Services\Finance\Api\Dto\InvoiceTaxSummaryDto;
use App\Services\Finance\Api\Dto\InvoiceTotalsDto;
use App\Support\Money\Money;

final class InvoiceApiAssembler
{
    public function summaryFromFinance(
        FinanceInvoice $invoice,
        ?EInvoiceDocument $document,
        ?EInvoiceDocumentRecord $record,
    ): InvoiceSummaryDto {
        return new InvoiceSummaryDto(
            id: (int) $invoice->id,
            sourceType: IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            documentNumber: (string) $invoice->invoice_number,
            documentType: (string) $invoice->type,
            documentUuid: $document !== null ? DocumentUuid::fromDocument($document) : null,
            issueDate: $invoice->issue_date?->toDateString(),
            currency: (string) ($invoice->currency ?: 'SAR'),
            total: Money::of($invoice->total ?? 0),
            taxAmount: Money::of($invoice->tax_amount ?? 0),
            businessStatus: $invoice->invoice_status ?? $invoice->status,
            paymentStatus: $invoice->payment_status,
            complianceStatus: $record?->compliance_status?->value,
            counterpartyName: $invoice->customer_name
                ?: $invoice->customer?->name
                ?: $invoice->supplier?->name,
        );
    }

    public function summaryFromNote(
        FinanceCreditNote $note,
        ?EInvoiceDocument $document,
        ?EInvoiceDocumentRecord $record,
    ): InvoiceSummaryDto {
        return new InvoiceSummaryDto(
            id: (int) $note->id,
            sourceType: $note->isCredit()
                ? IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE
                : IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE,
            documentNumber: (string) $note->note_number,
            documentType: (string) $note->type,
            documentUuid: $document !== null ? DocumentUuid::fromDocument($document) : null,
            issueDate: $note->issue_date?->toDateString(),
            currency: (string) ($note->currency ?: 'SAR'),
            total: Money::of($note->total ?? 0),
            taxAmount: Money::of($note->tax_amount ?? 0),
            businessStatus: (string) $note->status,
            paymentStatus: null,
            complianceStatus: $record?->compliance_status?->value,
            counterpartyName: $note->customer?->name,
        );
    }

    public function summaryFromPos(
        PosCashierInvoice $invoice,
        ?EInvoiceDocument $document,
        ?EInvoiceDocumentRecord $record,
    ): InvoiceSummaryDto {
        return new InvoiceSummaryDto(
            id: (int) $invoice->id,
            sourceType: IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE,
            documentNumber: (string) $invoice->invoice_number,
            documentType: 'pos',
            documentUuid: $document !== null ? DocumentUuid::fromDocument($document) : null,
            issueDate: $invoice->closed_at?->toDateString(),
            currency: (string) ($invoice->currency ?: 'SAR'),
            total: Money::of($invoice->total_amount ?? 0),
            taxAmount: Money::of($invoice->tax_amount ?? 0),
            businessStatus: (string) $invoice->status,
            paymentStatus: null,
            complianceStatus: $record?->compliance_status?->value,
            counterpartyName: $invoice->orders->first()?->customer?->name,
        );
    }

    public function detailsFromDocument(
        int $id,
        string $fallbackType,
        EInvoiceDocument $document,
        ?EInvoiceDocumentRecord $record,
        bool $xmlAvailable,
        bool $qrAvailable,
        ?string $securityStatus,
        ?string $dueDate = null,
        ?string $paymentMethod = null,
    ): InvoiceDetailsDto {
        $engine = $document->sourceType === IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE
            ? 'pos'
            : 'finance';

        return new InvoiceDetailsDto(
            id: $id,
            sourceType: $document->sourceType,
            documentNumber: $document->documentNumber,
            documentType: $fallbackType,
            documentUuid: DocumentUuid::fromDocument($document),
            issueDate: $document->issueDate !== null ? substr($document->issueDate, 0, 10) : null,
            issuedAt: $document->issuedAt,
            dueDate: $dueDate,
            supplyDate: $document->supplyDate,
            currency: $document->currency,
            seller: $this->party($document->seller),
            buyer: $this->party($document->buyer),
            lines: array_map(fn (EInvoiceLine $line): InvoiceLineDto => $this->line($line), $document->lines),
            tax: new InvoiceTaxSummaryDto(
                rate: $document->tax->rate,
                amount: $document->tax->amount,
                classification: $document->tax->classification->value,
                priceMode: $document->tax->priceMode,
                breakdown: $this->publicTaxBreakdown($document->tax->breakdown),
                engine: is_string($engine) ? $engine : null,
            ),
            totals: new InvoiceTotalsDto(
                subtotal: $document->totals->subtotal,
                discount: $document->totals->discount,
                taxableAmount: $document->totals->taxableAmount,
                taxAmount: $document->totals->taxAmount,
                total: $document->totals->total,
                amountPaid: $document->totals->amountPaid,
                amountDue: $document->totals->amountDue,
            ),
            compliance: $this->compliance(
                businessStatus: $document->businessStatus,
                paymentStatus: $document->paymentStatus,
                record: $record,
                xmlAvailable: $xmlAvailable,
                qrAvailable: $qrAvailable,
                securityStatus: $securityStatus,
                document: $document,
            ),
            references: new InvoiceReferencesDto(
                originalInvoiceId: $document->originalDocument?->invoiceId,
                originalInvoiceNumber: $document->originalDocument?->invoiceNumber,
                originalInvoiceIssueDate: $document->originalDocument?->invoiceIssueDate,
                originalInvoiceType: $document->originalDocument?->invoiceType,
                reason: $document->reason,
            ),
            notes: $document->notes,
            paymentMethod: $paymentMethod ?? (is_string($document->payment['method'] ?? null) ? $document->payment['method'] : null),
        );
    }

    public function draftFinanceDetails(FinanceInvoice $invoice): InvoiceDetailsDto
    {
        $invoice->loadMissing(['items', 'customer', 'supplier']);

        return new InvoiceDetailsDto(
            id: (int) $invoice->id,
            sourceType: IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            documentNumber: (string) $invoice->invoice_number,
            documentType: (string) $invoice->type,
            documentUuid: null,
            issueDate: $invoice->issue_date?->toDateString(),
            issuedAt: $invoice->issued_at?->toIso8601String(),
            dueDate: $invoice->due_date?->toDateString(),
            supplyDate: $invoice->supply_date?->toDateString(),
            currency: (string) ($invoice->currency ?: 'SAR'),
            seller: new InvoicePartyDto(null, null, null, null, null, null, false, null, null, null),
            buyer: new InvoicePartyDto(
                kind: $invoice->type === 'purchase' ? 'supplier' : 'customer',
                name: $invoice->customer_name ?: $invoice->customer?->name ?: $invoice->supplier?->name,
                vatNumber: $invoice->customer?->vat_number ?: $invoice->supplier?->vat_number,
                commercialRegistration: null,
                phone: $invoice->customer?->phone,
                email: $invoice->customer?->email,
                walkIn: false,
                addressLine: is_string($invoice->customer?->address) ? $invoice->customer->address : null,
                city: null,
                countryCode: null,
            ),
            lines: $invoice->items->map(fn ($item): InvoiceLineDto => new InvoiceLineDto(
                description: $item->description,
                productName: $item->product_name,
                quantity: (string) $item->quantity,
                unitPrice: Money::of($item->unit_price ?? 0),
                discount: Money::of($item->discount ?? 0),
                taxableAmount: $item->taxable_amount !== null ? Money::of($item->taxable_amount) : null,
                taxRate: $item->tax_rate !== null ? Money::of($item->tax_rate) : null,
                taxAmount: $item->tax_amount !== null ? Money::of($item->tax_amount) : null,
                total: Money::of($item->total ?? 0),
                taxClassification: $item->tax_profile_type,
                unitCode: $item->unit_code,
            ))->values()->all(),
            tax: new InvoiceTaxSummaryDto(
                rate: Money::of($invoice->tax_rate ?? 0),
                amount: Money::of($invoice->tax_amount ?? 0),
                classification: $invoice->tax_profile_type,
                priceMode: $invoice->tax_price_mode,
                breakdown: $this->publicTaxBreakdown(is_array($invoice->tax_breakdown) ? $invoice->tax_breakdown : null),
                engine: 'finance',
            ),
            totals: new InvoiceTotalsDto(
                subtotal: Money::of($invoice->subtotal ?? 0),
                discount: Money::of($invoice->discount ?? 0),
                taxableAmount: Money::of($invoice->taxable_amount ?? 0),
                taxAmount: Money::of($invoice->tax_amount ?? 0),
                total: Money::of($invoice->total ?? 0),
                amountPaid: Money::of($invoice->amount_paid ?? 0),
                amountDue: Money::of($invoice->amount_due ?? 0),
            ),
            compliance: new InvoiceComplianceDto(
                businessStatus: $invoice->invoice_status ?? $invoice->status,
                paymentStatus: $invoice->payment_status,
                complianceStatus: null,
                documentKind: null,
                invoiceType: null,
                transactionCode: null,
                xmlAvailable: false,
                qrAvailable: false,
                securityStatus: null,
            ),
            references: new InvoiceReferencesDto(null, null, null, null, null),
            notes: $invoice->notes,
            paymentMethod: $invoice->payment_terms,
        );
    }

    public function compliance(
        ?string $businessStatus,
        ?string $paymentStatus,
        ?EInvoiceDocumentRecord $record,
        bool $xmlAvailable,
        bool $qrAvailable,
        ?string $securityStatus,
        ?EInvoiceDocument $document = null,
    ): InvoiceComplianceDto {
        return new InvoiceComplianceDto(
            businessStatus: $businessStatus,
            paymentStatus: $paymentStatus,
            complianceStatus: $record?->compliance_status?->value,
            documentKind: $record?->document_kind?->value ?? $document?->kind()->value,
            invoiceType: $record?->type_code?->value ?? $document?->typeCode()?->value,
            transactionCode: $record?->transaction_code?->value ?? $document?->transactionCode()?->value,
            xmlAvailable: $xmlAvailable,
            qrAvailable: $qrAvailable,
            securityStatus: $securityStatus,
        );
    }

    public function securityStatus(?EInvoiceSecurityRecord $security): ?string
    {
        return $security?->security_status?->value;
    }

    private function party(EInvoiceParty $party): InvoicePartyDto
    {
        $line = $party->address->line
            ?? $party->address->street
            ?? null;

        return new InvoicePartyDto(
            kind: $party->kind,
            name: $party->name,
            vatNumber: $party->vatNumber,
            commercialRegistration: $party->commercialRegistration,
            phone: $party->phone,
            email: $party->email,
            walkIn: $party->walkIn,
            addressLine: $line,
            city: $party->address->city,
            countryCode: $party->address->countryCode,
        );
    }

    private function line(EInvoiceLine $line): InvoiceLineDto
    {
        return new InvoiceLineDto(
            description: $line->description,
            productName: $line->productName,
            quantity: $line->quantity,
            unitPrice: $line->unitPrice,
            discount: $line->discount,
            taxableAmount: $line->taxableAmount,
            taxRate: $line->taxRate,
            taxAmount: $line->taxAmount,
            total: $line->total,
            taxClassification: $line->taxClassification->value,
            unitCode: $line->unitCode,
        );
    }

    /**
     * @param  array<string, mixed>|null  $breakdown
     * @return list<array<string, mixed>>|null
     */
    private function publicTaxBreakdown(?array $breakdown): ?array
    {
        if ($breakdown === null) {
            return null;
        }

        $rows = [];
        foreach ($breakdown as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                'classification' => $row['classification'] ?? $row['profile_type'] ?? null,
                'rate' => isset($row['rate']) ? (string) $row['rate'] : null,
                'amount' => isset($row['amount']) ? (string) $row['amount'] : null,
                'taxable_amount' => isset($row['taxable_amount']) ? (string) $row['taxable_amount'] : null,
            ];
        }

        return $rows === [] ? null : array_values($rows);
    }
}
