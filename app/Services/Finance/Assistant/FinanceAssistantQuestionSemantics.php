<?php

namespace App\Services\Finance\Assistant;

use Illuminate\Support\Carbon;

final class FinanceAssistantQuestionSemantics
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
                'answer_ar' => 'ربح المنتج على مستوى التكلفة غير متاح حاليًا من السجلات المالية، ولا يمكن استخدام صافي ربح الشركة كبديل.',
                'answer_en' => 'Product-level cost and profit is not available from finance records yet, and company net profit cannot be used as a substitute.',
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

        if (preg_match('/صافي\s*الربح|net\s*profit|مجمل\s*الربح|إجمالي\s*الربح|gross\s*profit/u', $q) === 1) {
            return false;
        }

        if ($this->containsAny($q, ['مبيعات', 'مصاريف', 'مصروف', 'expense', 'sales', 'ملخص'])
            && str_contains($q, 'ربح')) {
            return false;
        }

        $hasProduct = $this->hasProductSubject($q);

        if ($hasProduct && $this->containsAny($q, ['ربح', 'هامش', 'margin', 'cogs', 'كلفة', 'تكلفة', 'كلف', 'profit', 'cost'])) {
            return true;
        }

        if (! $hasProduct && $this->isCompanyProfitAnalysis($q)) {
            return false;
        }

        return $hasProduct;
    }

    private function hasProductSubject(string $question): bool
    {
        if ($this->containsAny($question, ['منتج', 'product', 'صنف', 'item', 'برغر', 'burger', 'بيتزا', 'pizza'])) {
            return true;
        }

        foreach ($this->profitSubjectTokens($question) as $token) {
            if (! $this->isFinancialAnalysisToken($token)) {
                return true;
            }
        }

        return false;
    }

    private function isCompanyProfitAnalysis(string $question): bool
    {
        return $this->containsAny($question, [
            'هذا الشهر', 'هذه السنة', 'هذا العام', 'الشهر الماضي', 'العام الماضي',
            'السنة الماضية', 'انخفض', 'انخفاض', 'زاد', 'زيادة', 'نازل', 'نزل',
            'أقل', 'أكثر', 'ليش', 'لماذا', 'why', 'قارن', 'مقارنة', 'compare',
            'ربحنا', 'الربح', 'profit',
        ]);
    }

    /**
     * @return list<string>
     */
    private function profitSubjectTokens(string $question): array
    {
        preg_match_all(
            '/(?:ربح|هامش|كلفة|تكلفة|margin|profit|cost)\s+(?:و(?:ربح|هامش|كلفة|تكلفة|profit|cost)\s+)?(?:ال|لل)?([\p{L}\d]+)/u',
            $question,
            $matches,
        );

        $tokens = [];
        foreach ($matches[1] ?? [] as $token) {
            $normalized = trim(mb_strtolower((string) $token));
            if ($normalized !== '') {
                $tokens[] = $normalized;
            }
        }

        return $tokens;
    }

    private function isFinancialAnalysisToken(string $token): bool
    {
        $token = preg_replace('/^ال/u', '', $token) ?? $token;

        return in_array($token, [
            'هذا', 'هذه', 'ذلك', 'تلك', 'شهر', 'عام', 'سنة', 'ربع', 'ماضي', 'الماضي',
            'حالي', 'الحالي', 'نازل', 'نزل', 'أقل', 'أكثر', 'لماذا', 'ليش', 'صافي',
            'مجمل', 'إجمالي', 'اليوم', 'أسبوع', 'اسبوع', 'الماضية', 'القادم', 'القادمة',
            'الحالية', 'الحاليه', 'شركه', 'شركة', 'مؤسسة', 'المؤسسة', 'عمل', 'العمل',
            'نشاط', 'المتجر', 'مشروع', 'المشروع', 'بيزنس', 'profit', 'month', 'year',
            'week', 'today', 'this', 'last', 'our', 'the', 'down', 'up', 'less', 'more',
            'company', 'business', 'store', 'shop',
        ], true);
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

        if (preg_match('/(?:مبيعات|sales|ربح|مصاريف|expense|payroll|رواتب)\s*(?:في|in|سنة)?\s*(\d{4})/ui', $q, $matches)) {
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

        $minYear = (int) config('finance_assistant.min_period_year', 2000);

        return (int) substr($from, 0, 4) >= $minYear
            && (int) substr($to, 0, 4) >= $minYear;
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
