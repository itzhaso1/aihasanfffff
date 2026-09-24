<?php

namespace App\Services\Cashier\Assistant;

use Illuminate\Support\Carbon;

final class CashierAssistantQuestionSemantics
{
    /**
     * @return array{type: string, tool: string, reason: string, requested_period?: array{from: string, to: string, label: string}, answer_ar: string, answer_en: string}|null
     */
    public function evaluate(string $question): ?array
    {
        if ($this->isProductProfitQuestion($question)) {
            return [
                'type' => 'product_profit_not_available',
                'tool' => 'product.profit',
                'reason' => 'product_profit_not_available',
                'answer_ar' => 'ربح المنتج على مستوى التكلفة غير متاح حاليًا من سجلات الكاشير، ولا يمكن استخدام صافي ربح الشركة كبديل.',
                'answer_en' => 'Product-level cost and profit is not available from cashier records yet, and company net profit cannot be used as a substitute.',
            ];
        }

        $requested = $this->extractExplicitRequestedPeriod($question);
        if ($requested !== null && ! $this->isSupportedPeriodRange($requested['from'], $requested['to'])) {
            return [
                'type' => 'period_not_supported',
                'tool' => 'period.request',
                'reason' => 'period_not_supported',
                'requested_period' => $requested,
                'answer_ar' => 'لا أستطيع الإجابة عن الفترة المطلوبة من السجلات المتاحة.',
                'answer_en' => 'I cannot answer for the requested period from available records.',
            ];
        }

        return null;
    }

    public function isProductProfitQuestion(string $question): bool
    {
        $q = mb_strtolower(trim($question));

        if ($q === '') {
            return false;
        }

        if (preg_match('/صافي\s*الربح|net\s*profit/u', $q)) {
            return false;
        }

        if ($this->containsAny($q, ['مبيعات', 'مصاريف', 'مصروف', 'expense', 'sales'])
            && str_contains($q, 'ربح')) {
            return false;
        }

        if ($this->containsAny($q, ['هامش', 'margin'])) {
            return true;
        }

        if (str_contains($q, 'كلفة') && str_contains($q, 'ربح')) {
            return true;
        }

        if (str_contains($q, 'ربح') && $this->containsAny($q, ['منتج', 'product', 'برغر', 'burger', 'بيتزا', 'pizza'])) {
            return true;
        }

        if (preg_match('/(?:كم\s+)?ربح\s+(?:ال|منتج\s+)?[\p{L}\d][\p{L}\d\s]{0,40}[؟?]?/u', $q)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{from: string, to: string, label: string}|null
     */
    public function extractExplicitRequestedPeriod(string $question): ?array
    {
        $q = trim($question);

        if (preg_match('/(\d{4}-\d{2}-\d{2})\s*(?:إلى|to|–|-)\s*(\d{4}-\d{2}-\d{2})/ui', $q, $matches)) {
            return $this->normalizeRange($matches[1], $matches[2], 'custom');
        }

        if (preg_match('/(?:من|from)\s*(\d{4}-\d{2}-\d{2}).*(?:إلى|to)\s*(\d{4}-\d{2}-\d{2})/ui', $q, $matches)) {
            return $this->normalizeRange($matches[1], $matches[2], 'custom');
        }

        if (preg_match('/(?:سنة|عام|year)\s*(\d{4})/ui', $q, $matches)) {
            return $this->yearRange((int) $matches[1]);
        }

        if (preg_match('/(?:مبيعات|sales|بيع|بعنا)\s*(?:في|in|سنة)?\s*(\d{4})/ui', $q, $matches)) {
            return $this->yearRange((int) $matches[1]);
        }

        return null;
    }

    public function isSupportedPeriodRange(string $from, string $to): bool
    {
        $today = now()->toDateString();

        if ($from > $today) {
            return false;
        }

        $minYear = (int) config('cashier_assistant.min_period_year', 2000);

        return (int) substr($from, 0, 4) >= $minYear
            && (int) substr($to, 0, 4) >= $minYear;
    }

    public function hasSupportedPresetPeriodKeyword(string $question): bool
    {
        $q = mb_strtolower(trim($question));

        return str_contains($q, 'اليوم')
            || str_contains($q, 'today')
            || str_contains($q, 'الأسبوع')
            || str_contains($q, 'week')
            || str_contains($q, 'الشهر')
            || str_contains($q, 'month')
            || str_contains($q, 'الشهر الماضي')
            || str_contains($q, 'last month');
    }

    /**
     * @return array{from: string, to: string, label: string}
     */
    private function yearRange(int $year): array
    {
        return $this->normalizeRange(
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
            'year:'.$year,
        );
    }

    /**
     * @return array{from: string, to: string, label: string}
     */
    private function normalizeRange(string $from, string $to, string $label): array
    {
        $start = Carbon::parse($from)->toDateString();
        $end = Carbon::parse($to)->toDateString();

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return [
            'from' => $start,
            'to' => $end,
            'label' => $label,
        ];
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
