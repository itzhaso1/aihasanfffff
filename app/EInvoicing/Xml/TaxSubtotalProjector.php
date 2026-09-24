<?php

namespace App\EInvoicing\Xml;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\EInvoiceLine;
use App\Enums\EInvoicing\ElectronicTaxClassification;
use App\Support\Money\Money;

/**
 * Projects {@see EInvoiceDocument} snapshot tax breakdown into UBL TaxSubtotal rows.
 *
 * Uses persisted `tax.breakdown` from the immutable snapshot; does not recalculate VAT.
 */
final class TaxSubtotalProjector
{
    /**
     * @return list<TaxSubtotalRow>
     */
    public function project(EInvoiceDocument $document): array
    {
        $breakdown = $document->tax->breakdown;
        if (is_array($breakdown) && $breakdown !== []) {
            $rows = [];
            foreach ($breakdown as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $rows[] = $this->rowFromBreakdownEntry($document, $entry);
            }

            if ($rows !== []) {
                return $rows;
            }
        }

        return [$this->headerFallbackRow($document)];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function rowFromBreakdownEntry(EInvoiceDocument $document, array $entry): TaxSubtotalRow
    {
        $classification = ElectronicTaxClassification::fromSnapshotValue($entry['tax_profile_type'] ?? null);
        $rate = Money::of($entry['tax_rate'] ?? 0);
        [$exemptionCode, $exemptionReason] = $this->exemptionFor($document, $classification, $rate);

        return new TaxSubtotalRow(
            taxableAmount: Money::of($entry['taxable_amount'] ?? 0),
            taxAmount: Money::of($entry['tax_amount'] ?? 0),
            classification: $classification,
            rate: $rate,
            exemptionCode: $exemptionCode,
            exemptionReason: $exemptionReason,
        );
    }

    private function headerFallbackRow(EInvoiceDocument $document): TaxSubtotalRow
    {
        $classification = $document->tax->classification;
        $rate = $document->tax->rate;
        [$exemptionCode, $exemptionReason] = $this->exemptionFor($document, $classification, $rate);

        return new TaxSubtotalRow(
            taxableAmount: $document->totals->taxableAmount,
            taxAmount: $document->tax->amount,
            classification: $classification,
            rate: $rate,
            exemptionCode: $exemptionCode,
            exemptionReason: $exemptionReason,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function exemptionFor(
        EInvoiceDocument $document,
        ElectronicTaxClassification $classification,
        string $rate,
    ): array {
        foreach ($document->lines as $line) {
            if ($line->taxClassification !== $classification) {
                continue;
            }
            if ($line->taxRate !== null && Money::cmp($line->taxRate, $rate) !== 0) {
                continue;
            }

            $code = is_string($line->exemptionCode) && $line->exemptionCode !== ''
                ? $line->exemptionCode
                : null;
            $reason = is_string($line->exemptionReason) && $line->exemptionReason !== ''
                ? $line->exemptionReason
                : null;

            if ($code !== null || $reason !== null) {
                return [$code, $reason];
            }
        }

        return [null, null];
    }
}

final readonly class TaxSubtotalRow
{
    public function __construct(
        public string $taxableAmount,
        public string $taxAmount,
        public ElectronicTaxClassification $classification,
        public string $rate,
        public ?string $exemptionCode,
        public ?string $exemptionReason,
    ) {}
}
