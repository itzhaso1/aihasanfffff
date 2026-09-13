<?php

namespace App\EInvoicing;

use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\InvoiceTransactionCode;
use App\Enums\EInvoicing\InvoiceTypeCode;
use App\Enums\Finance\TaxDocumentSubtype;
use App\Models\Finance\IssuedDocumentSnapshot;
use RuntimeException;

/**
 * Single mapping from issued-snapshot source + subtype to electronic document type.
 * Credit/debit notes use the original invoice subtype when the snapshot captured it.
 * POS invoices keep a nullable subtype and never guess simplified/standard.
 */
final class InvoiceTypeCatalog
{
    /**
     * Architecture catalog. Transaction codes stay null when the snapshot
     * does not carry a standard/simplified subtype (POS, or notes without
     * an original invoice classification).
     *
     * @return array<string, array{kind: string, type_code: string|null, transaction_code: string|null}>
     */
    public static function definitions(): array
    {
        return [
            ElectronicDocumentKind::TaxInvoice->value => [
                'kind' => ElectronicDocumentKind::TaxInvoice->value,
                'type_code' => InvoiceTypeCode::TaxInvoice->value,
                'transaction_code' => InvoiceTransactionCode::Standard->value,
            ],
            ElectronicDocumentKind::SimplifiedTaxInvoice->value => [
                'kind' => ElectronicDocumentKind::SimplifiedTaxInvoice->value,
                'type_code' => InvoiceTypeCode::TaxInvoice->value,
                'transaction_code' => InvoiceTransactionCode::Simplified->value,
            ],
            ElectronicDocumentKind::CreditNote->value => [
                'kind' => ElectronicDocumentKind::CreditNote->value,
                'type_code' => InvoiceTypeCode::CreditNote->value,
                'transaction_code' => InvoiceTransactionCode::Standard->value,
            ],
            ElectronicDocumentKind::SimplifiedCreditNote->value => [
                'kind' => ElectronicDocumentKind::SimplifiedCreditNote->value,
                'type_code' => InvoiceTypeCode::CreditNote->value,
                'transaction_code' => InvoiceTransactionCode::Simplified->value,
            ],
            ElectronicDocumentKind::DebitNote->value => [
                'kind' => ElectronicDocumentKind::DebitNote->value,
                'type_code' => InvoiceTypeCode::DebitNote->value,
                'transaction_code' => InvoiceTransactionCode::Standard->value,
            ],
            ElectronicDocumentKind::SimplifiedDebitNote->value => [
                'kind' => ElectronicDocumentKind::SimplifiedDebitNote->value,
                'type_code' => InvoiceTypeCode::DebitNote->value,
                'transaction_code' => InvoiceTransactionCode::Simplified->value,
            ],
            ElectronicDocumentKind::PosCashierInvoice->value => [
                'kind' => ElectronicDocumentKind::PosCashierInvoice->value,
                'type_code' => null,
                'transaction_code' => null,
            ],
            ElectronicDocumentKind::PurchaseInvoice->value => [
                'kind' => ElectronicDocumentKind::PurchaseInvoice->value,
                'type_code' => null,
                'transaction_code' => null,
            ],
        ];
    }

    public static function fromSnapshot(IssuedDocumentSnapshot $snapshot): InvoiceType
    {
        $sourceType = (string) $snapshot->source_type;
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];
        $subtype = self::subtypeFromDocument($document);

        return match ($sourceType) {
            IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE => self::financeInvoice($document, $subtype),
            IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE => self::note(
                ElectronicDocumentKind::CreditNote,
                ElectronicDocumentKind::SimplifiedCreditNote,
                InvoiceTypeCode::CreditNote,
                $subtype,
            ),
            IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE => self::note(
                ElectronicDocumentKind::DebitNote,
                ElectronicDocumentKind::SimplifiedDebitNote,
                InvoiceTypeCode::DebitNote,
                $subtype,
            ),
            IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE => new InvoiceType(
                ElectronicDocumentKind::PosCashierInvoice,
                null,
                $subtype,
            ),
            default => throw new RuntimeException('نوع مصدر اللقطة غير مدعوم للمستند الإلكتروني.'),
        };
    }

    /**
     * @return list<string>
     */
    public static function supportedSourceTypes(): array
    {
        return [
            IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE,
            IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE,
            IssuedDocumentSnapshot::SOURCE_POS_CASHIER_INVOICE,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function financeInvoice(array $document, ?InvoiceTransactionCode $subtype): InvoiceType
    {
        if (($document['type'] ?? null) === 'purchase') {
            return new InvoiceType(ElectronicDocumentKind::PurchaseInvoice, null, $subtype);
        }

        $isSimplified = $subtype === InvoiceTransactionCode::Simplified;

        return new InvoiceType(
            $isSimplified ? ElectronicDocumentKind::SimplifiedTaxInvoice : ElectronicDocumentKind::TaxInvoice,
            InvoiceTypeCode::TaxInvoice,
            $subtype ?? InvoiceTransactionCode::Standard,
        );
    }

    private static function note(
        ElectronicDocumentKind $standardKind,
        ElectronicDocumentKind $simplifiedKind,
        InvoiceTypeCode $typeCode,
        ?InvoiceTransactionCode $subtype,
    ): InvoiceType {
        if ($subtype === InvoiceTransactionCode::Simplified) {
            return new InvoiceType($simplifiedKind, $typeCode, $subtype);
        }

        if ($subtype === InvoiceTransactionCode::Standard) {
            return new InvoiceType($standardKind, $typeCode, $subtype);
        }

        return new InvoiceType($standardKind, $typeCode, null);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function subtypeFromDocument(array $document): ?InvoiceTransactionCode
    {
        $raw = $document['tax_document_subtype'] ?? $document['subtype'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $subtype = TaxDocumentSubtype::tryFrom($raw);

        return match ($subtype) {
            TaxDocumentSubtype::Simplified => InvoiceTransactionCode::Simplified,
            TaxDocumentSubtype::Standard => InvoiceTransactionCode::Standard,
            default => null,
        };
    }
}
