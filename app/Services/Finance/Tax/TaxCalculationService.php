<?php

namespace App\Services\Finance\Tax;

use App\Enums\Finance\TaxPriceMode;
use App\Enums\Finance\TaxProfileType;
use App\Models\Workspace;
use App\Support\Money\Money;

/**
 * Authoritative invoice-domain tax calculator.
 *
 * InvoiceService / CreditNoteService consume calculateDocument() and persist
 * the result. TaxService remains a float-returning compatibility facade.
 *
 * This Tax Engine is NOT a ZATCA integration. It does not generate XML, QR,
 * CSID, signatures, clearance, or reporting payloads.
 */
class TaxCalculationService
{
    /**
     * Bootstrap/fallback standard VAT rate used only when a workspace has
     * no finance settings and no default tax rate row yet.
     *
     * This is NOT a legal/tax-rate database. Workspace defaults live on
     * finance_settings.default_vat_rate and finance_tax_rates.
     */
    public const FALLBACK_STANDARD_RATE = 15.00;

    public function __construct(
        private readonly TaxRateResolver $rateResolver,
    ) {}

    /**
     * @return array{type:string, rate:float}
     */
    public function defaultProfileForWorkspace(Workspace $workspace): array
    {
        return $this->rateResolver->defaultProfile((int) $workspace->id);
    }

    public function normalizeProfileType(?string $type, ?string $fallback = null): string
    {
        return TaxProfileType::tryFrom((string) $type)?->value
            ?? ($fallback ?? TaxProfileType::Standard->value);
    }

    /**
     * Permissive compatibility calculator used by expenses and purchase orders.
     *
     * @return array{taxable_amount:float,tax_amount:float,total:float}
     */
    public function calculateAmount(float $amount, string $taxType, float $rate): array
    {
        $taxableAmount = Money::of($amount);
        $taxAmount = $this->isTaxable($taxType) && $rate > 0
            ? Money::percentOf($taxableAmount, $rate)
            : Money::fromMinor(0);

        return [
            'taxable_amount' => (float) $taxableAmount,
            'tax_amount' => (float) $taxAmount,
            'total' => (float) Money::add($taxableAmount, $taxAmount),
        ];
    }

    /**
     * Permissive exclusive-price line calculator. Document validation lives on
     * calculateDocument(); this method must not start rejecting expense/PO input.
     *
     * @return array{taxable_amount:float,tax_amount:float,total:float}
     */
    public function calculateLine(float $quantity, float $unitPrice, float $discount, string $taxType, float $rate): array
    {
        $line = $this->calculateExclusiveLine(
            max(0.0, $quantity),
            max(0.0, $unitPrice),
            max(0.0, $discount),
            $this->normalizeProfileType($taxType),
            max(0.0, $rate),
        );

        return [
            'taxable_amount' => $line['taxable_amount'],
            'tax_amount' => $line['tax_amount'],
            'total' => $line['total'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal:float,discount:float,taxable_amount:float,tax_amount:float,total:float}
     */
    public function totals(array $items): array
    {
        $subtotal = '0.00';
        $discount = '0.00';
        $taxable = '0.00';
        $tax = '0.00';
        $total = '0.00';

        foreach ($items as $item) {
            $lineGross = Money::quantityTimesUnitPrice(
                (float) ($item['quantity'] ?? 0),
                (float) ($item['unit_price'] ?? 0)
            );
            $subtotal = Money::add($subtotal, $lineGross);
            $discount = Money::add($discount, (float) ($item['discount'] ?? 0));
            $taxable = Money::add($taxable, (float) ($item['taxable_amount'] ?? 0));
            $tax = Money::add($tax, (float) ($item['tax_amount'] ?? 0));
            $total = Money::add($total, (float) ($item['total'] ?? 0));
        }

        return [
            'subtotal' => (float) $subtotal,
            'discount' => (float) $discount,
            'taxable_amount' => (float) $taxable,
            'tax_amount' => (float) $tax,
            'total' => (float) $total,
        ];
    }

    public function isTaxable(string $taxType): bool
    {
        return $this->normalizeProfileType($taxType) === TaxProfileType::Standard->value;
    }

    public function roundMoney(float $amount): float
    {
        return Money::round($amount);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawItems
     */
    public function calculateDocument(
        Workspace $workspace,
        array $rawItems,
        TaxPriceMode $priceMode = TaxPriceMode::Exclusive,
        ?string $headerClassification = null,
        mixed $headerRate = null,
    ): TaxCalculationResult {
        if ($rawItems === []) {
            throw new TaxCalculationException('يجب أن يحتوي المستند على بند واحد على الأقل.');
        }

        $headerType = $this->normalizeProfileType($headerClassification, TaxProfileType::Standard->value);
        $defaultProfile = $this->rateResolver->defaultProfile((int) $workspace->id);
        $documentDefaultRate = $this->rateResolver->hasExplicitRate($headerRate)
            ? $headerRate
            : $defaultProfile['rate'];

        $lineResults = [];
        foreach (array_values($rawItems) as $index => $rawItem) {
            $lineResults[] = $this->calculateStrictLine(
                (int) $workspace->id,
                is_array($rawItem) ? $rawItem : [],
                $priceMode,
                $headerType,
                $documentDefaultRate,
                $index + 1,
            );
        }

        return $this->assembleResult($priceMode, $lineResults);
    }

    /**
     * Recalculate from persisted line inputs. Persisted rates are treated as
     * explicit so yesterday's invoice does not pick up today's workspace rate.
     *
     * @param  iterable<int, object|array<string, mixed>>  $lines
     */
    public function calculateFromPersistedLines(
        Workspace $workspace,
        iterable $lines,
        TaxPriceMode $priceMode = TaxPriceMode::Exclusive,
    ): TaxCalculationResult {
        $rawItems = [];
        foreach ($lines as $line) {
            $row = is_array($line) ? $line : [
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount' => $line->discount,
                'tax_profile_type' => $line->tax_profile_type ?? null,
                'tax_rate' => $line->tax_rate,
                'exemption_reason' => $line->exemption_reason ?? null,
                'exemption_code' => $line->exemption_code ?? null,
            ];
            $rawItems[] = $row;
        }

        return $this->calculateDocument($workspace, $rawItems, $priceMode);
    }

    /**
     * @param  array<string, mixed>  $rawItem
     */
    private function calculateStrictLine(
        int $workspaceId,
        array $rawItem,
        TaxPriceMode $priceMode,
        string $headerType,
        mixed $documentDefaultRate,
        int $lineNumber,
    ): TaxLineResult {
        $rawType = $rawItem['tax_type'] ?? $rawItem['tax_profile_type'] ?? null;
        if ($rawType !== null && $rawType !== '' && TaxProfileType::tryFrom((string) $rawType) === null) {
            throw new TaxCalculationException('تصنيف الضريبة غير صالح في البند رقم '.$lineNumber.'.');
        }

        $classification = TaxProfileType::from(
            $this->normalizeProfileType(
                is_string($rawType) || is_int($rawType) ? (string) $rawType : null,
                $headerType
            )
        );

        $quantity = (float) ($rawItem['quantity'] ?? 0);
        $unitPrice = (float) ($rawItem['unit_price'] ?? 0);
        $discount = (float) ($rawItem['discount'] ?? 0);

        if ($quantity <= 0) {
            throw new TaxCalculationException('كمية البند رقم '.$lineNumber.' غير صالحة.');
        }
        if ($unitPrice < 0) {
            throw new TaxCalculationException('سعر وحدة البند رقم '.$lineNumber.' غير صالح.');
        }
        if ($discount < 0) {
            throw new TaxCalculationException('خصم البند رقم '.$lineNumber.' لا يمكن أن يكون سالباً.');
        }

        $rate = $this->resolveLineRate(
            $workspaceId,
            $classification,
            $rawItem['tax_rate'] ?? null,
            array_key_exists('tax_rate', $rawItem),
            $documentDefaultRate,
            $lineNumber,
        );

        $exemption = $this->normalizeExemption($classification, $rawItem, $lineNumber);

        $amounts = $priceMode === TaxPriceMode::Inclusive
            ? $this->calculateInclusiveLine($quantity, $unitPrice, $discount, $classification->value, $rate)
            : $this->calculateExclusiveLine($quantity, $unitPrice, $discount, $classification->value, $rate);

        $this->assertLineInvariants($classification, $rate, $amounts, $lineNumber);

        return new TaxLineResult(
            quantity: (float) number_format($quantity, 3, '.', ''),
            unitPrice: (float) Money::of($unitPrice),
            grossAmount: $amounts['gross_amount'],
            discountAmount: $amounts['discount'],
            taxableAmount: $amounts['taxable_amount'],
            classification: $classification,
            taxRate: $rate,
            taxAmount: $amounts['tax_amount'],
            total: $amounts['total'],
            priceMode: $priceMode,
            exemptionReason: $exemption['reason'],
            exemptionCode: $exemption['code'],
        );
    }

    private function resolveLineRate(
        int $workspaceId,
        TaxProfileType $classification,
        mixed $explicitRate,
        bool $rateKeyPresent,
        mixed $documentDefaultRate,
        int $lineNumber,
    ): float {
        if ($classification !== TaxProfileType::Standard) {
            if ($this->rateResolver->hasExplicitRate($explicitRate) && (float) $explicitRate < 0) {
                throw new TaxCalculationException('نسبة الضريبة لا يمكن أن تكون سالبة في البند رقم '.$lineNumber.'.');
            }

            return 0.0;
        }

        $rate = $this->rateResolver->resolveStandardRate(
            $workspaceId,
            $rateKeyPresent ? $explicitRate : null,
            $documentDefaultRate,
        );

        if ($rate <= 0) {
            throw new TaxCalculationException(
                'التصنيف القياسي يتطلب نسبة ضريبة أكبر من صفر في البند رقم '.$lineNumber.'. استخدم صفري أو معفى أو خارج النطاق.'
            );
        }

        return $rate;
    }

    /**
     * @param  array<string, mixed>  $rawItem
     * @return array{reason:?string, code:?string}
     */
    private function normalizeExemption(TaxProfileType $classification, array $rawItem, int $lineNumber): array
    {
        $reason = $this->nullableTrim($rawItem['exemption_reason'] ?? null);
        $code = $this->nullableTrim($rawItem['exemption_code'] ?? null);

        if ($classification === TaxProfileType::Standard && ($reason !== null || $code !== null)) {
            throw new TaxCalculationException('لا يمكن حفظ سبب إعفاء على بند بتصنيف قياسي (البند رقم '.$lineNumber.').');
        }

        if ($code !== null && ! preg_match('/^[A-Za-z0-9._-]{1,32}$/', $code)) {
            throw new TaxCalculationException('رمز الإعفاء في البند رقم '.$lineNumber.' غير صالح.');
        }

        return [
            'reason' => $reason,
            'code' => $code,
        ];
    }

    /**
     * @return array{gross_amount:float,discount:float,taxable_amount:float,tax_amount:float,total:float}
     */
    private function calculateExclusiveLine(
        float $quantity,
        float $unitPrice,
        float $discount,
        string $taxType,
        float $rate,
    ): array {
        $gross = Money::quantityTimesUnitPrice($quantity, $unitPrice);
        $requestedDiscount = Money::of($discount);
        $lineDiscount = Money::cmp($requestedDiscount, $gross) > 0 ? $gross : $requestedDiscount;
        $taxable = Money::sub($gross, $lineDiscount);
        $taxAmount = $this->isTaxable($taxType) && $rate > 0
            ? Money::percentOf($taxable, $rate)
            : Money::fromMinor(0);

        return [
            'gross_amount' => (float) $gross,
            'discount' => (float) $lineDiscount,
            'taxable_amount' => (float) $taxable,
            'tax_amount' => (float) $taxAmount,
            'total' => (float) Money::add($taxable, $taxAmount),
        ];
    }

    /**
     * Inclusive: unit price already contains VAT.
     * net = gross − discount
     * tax = round(net × rate / (100 + rate))
     * taxable = net − tax
     * total = net
     *
     * @return array{gross_amount:float,discount:float,taxable_amount:float,tax_amount:float,total:float}
     */
    private function calculateInclusiveLine(
        float $quantity,
        float $unitPrice,
        float $discount,
        string $taxType,
        float $rate,
    ): array {
        $gross = Money::quantityTimesUnitPrice($quantity, $unitPrice);
        $requestedDiscount = Money::of($discount);
        $lineDiscount = Money::cmp($requestedDiscount, $gross) > 0 ? $gross : $requestedDiscount;
        $net = Money::sub($gross, $lineDiscount);
        $taxAmount = $this->isTaxable($taxType) && $rate > 0
            ? Money::extractInclusiveTax($net, $rate)
            : Money::fromMinor(0);
        $taxable = Money::sub($net, $taxAmount);

        return [
            'gross_amount' => (float) $gross,
            'discount' => (float) $lineDiscount,
            'taxable_amount' => (float) $taxable,
            'tax_amount' => (float) $taxAmount,
            'total' => (float) $net,
        ];
    }

    /**
     * @param  array{taxable_amount:float,tax_amount:float,total:float}  $amounts
     */
    private function assertLineInvariants(
        TaxProfileType $classification,
        float $rate,
        array $amounts,
        int $lineNumber,
    ): void {
        if (Money::cmp($amounts['taxable_amount'], 0) < 0) {
            throw new TaxCalculationException('الأساس الخاضع للضريبة في البند رقم '.$lineNumber.' غير صالح.');
        }

        if ($classification !== TaxProfileType::Standard && Money::cmp($amounts['tax_amount'], 0) !== 0) {
            throw new TaxCalculationException('لا يمكن احتساب ضريبة قياسية على بند غير قياسي (البند رقم '.$lineNumber.').');
        }

        if ($classification === TaxProfileType::Standard && $rate <= 0) {
            throw new TaxCalculationException('التصنيف القياسي يتطلب نسبة صالحة في البند رقم '.$lineNumber.'.');
        }

        $expectedTotal = (float) Money::add($amounts['taxable_amount'], $amounts['tax_amount']);
        if (Money::cmp($expectedTotal, $amounts['total']) !== 0) {
            throw new TaxCalculationException('إجمالي البند رقم '.$lineNumber.' لا يطابق الخاضع للضريبة مضافاً إليه الضريبة.');
        }
    }

    /**
     * @param  array<int, TaxLineResult>  $lines
     */
    private function assembleResult(TaxPriceMode $priceMode, array $lines): TaxCalculationResult
    {
        $subtotal = '0.00';
        $discount = '0.00';
        $taxable = '0.00';
        $tax = '0.00';
        $total = '0.00';
        $buckets = [];

        foreach ($lines as $line) {
            $subtotal = Money::add($subtotal, $line->grossAmount);
            $discount = Money::add($discount, $line->discountAmount);
            $taxable = Money::add($taxable, $line->taxableAmount);
            $tax = Money::add($tax, $line->taxAmount);
            $total = Money::add($total, $line->total);

            $key = $line->classification->value.'|'.number_format($line->taxRate, 2, '.', '');
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'classification' => $line->classification,
                    'tax_rate' => $line->taxRate,
                    'taxable_amount' => '0.00',
                    'tax_amount' => '0.00',
                    'line_count' => 0,
                ];
            }
            $buckets[$key]['taxable_amount'] = Money::add($buckets[$key]['taxable_amount'], $line->taxableAmount);
            $buckets[$key]['tax_amount'] = Money::add($buckets[$key]['tax_amount'], $line->taxAmount);
            $buckets[$key]['line_count']++;
        }

        if (Money::cmp($total, Money::add($taxable, $tax)) !== 0) {
            throw new TaxCalculationException('إجمالي المستند لا يطابق مجموع المبلغ الخاضع للضريبة والضريبة.');
        }

        $lineTaxSum = '0.00';
        $lineTaxableSum = '0.00';
        $lineTotalSum = '0.00';
        foreach ($lines as $line) {
            $lineTaxSum = Money::add($lineTaxSum, $line->taxAmount);
            $lineTaxableSum = Money::add($lineTaxableSum, $line->taxableAmount);
            $lineTotalSum = Money::add($lineTotalSum, $line->total);
        }

        if (Money::cmp($lineTaxSum, $tax) !== 0
            || Money::cmp($lineTaxableSum, $taxable) !== 0
            || Money::cmp($lineTotalSum, $total) !== 0) {
            throw new TaxCalculationException('مجاميع بنود الضريبة لا تتطابق مع رأس المستند.');
        }

        $categoryTotals = [];
        foreach ($buckets as $bucket) {
            $categoryTotals[] = new TaxCategoryTotal(
                classification: $bucket['classification'],
                taxRate: (float) number_format((float) $bucket['tax_rate'], 2, '.', ''),
                taxableAmount: (float) $bucket['taxable_amount'],
                taxAmount: (float) $bucket['tax_amount'],
                lineCount: $bucket['line_count'],
            );
        }

        return new TaxCalculationResult(
            priceMode: $priceMode,
            subtotal: (float) $subtotal,
            discountTotal: (float) $discount,
            taxableAmount: (float) $taxable,
            taxTotal: (float) $tax,
            grandTotal: (float) $total,
            lines: $lines,
            categoryTotals: $categoryTotals,
        );
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
