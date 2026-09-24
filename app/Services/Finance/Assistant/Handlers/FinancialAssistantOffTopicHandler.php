<?php

namespace App\Services\Finance\Assistant\Handlers;

final class FinancialAssistantOffTopicHandler
{
    /**
     * @return array{intent: string, answer_ar: string, answer_en: string, reason: string}|null
     */
    public function match(string $question): ?array
    {
        if ($this->containsFinancialIntent($question)) {
            return null;
        }

        $normalized = $this->normalize($question);
        if ($normalized === '') {
            return null;
        }

        if (! $this->isOffTopic($normalized)) {
            return null;
        }

        return [
            'intent' => 'off_topic',
            'reason' => 'outside_hasem_finance_scope',
            'answer_ar' => 'أنا مخصص لمساعدتك في شغل HASEM المالي والإداري، مثل الفواتير والمبيعات والعملاء والعقود والمدفوعات والتقارير.
إذا عندك سؤال عن وضعك المالي أو فاتورة أو عميل، اسألني مباشرة.',
            'answer_en' => 'I focus on HASEM finance and operations — invoices, sales, customers, contracts, payments, and reports.
If you have a question about your financial position, an invoice, or a customer, ask me directly.',
        ];
    }

    private function containsFinancialIntent(string $question): bool
    {
        $q = mb_strtolower(trim($question));

        $signals = [
            'مبيعات', 'فاتورة', 'فواتير', 'ربح', 'مصروف', 'مصاريف', 'عميل', 'عملاء',
            'مورد', 'موردين', 'عقد', 'عقود', 'دفع', 'مدفوع', 'متأخر', 'مستحق', 'وضع',
            'وضعي', 'وضعنا', 'قديش', 'invoice', 'profit', 'sales', 'expense', 'customer',
            'supplier', 'contract', 'payment', 'overdue', 'tax', 'ضريبة', 'hasem',
            'حاسم', 'inv-', 'ctr-',
        ];

        foreach ($signals as $signal) {
            if (str_contains($q, $signal)) {
                return true;
            }
        }

        return preg_match('/\b(?:كم|how\s*much|compare|قارن)\b/u', $q) === 1;
    }

    private function normalize(string $question): string
    {
        $q = mb_strtolower(trim($question));
        $q = preg_replace('/[!؟?.,،…~]+/u', '', $q) ?? $q;
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;

        return trim($q);
    }

    private function isOffTopic(string $normalized): bool
    {
        $patterns = [
            '/(?:فيلم|مسلسل|series|movie|netflix|youtube\s*video)/u',
            '/(?:طقس|weather|temperature|forecast)/u',
            '/(?:وصفة|recipe|cooking|طبخ)/u',
            '/(?:رأيك\s*(?:ب|في|about)|what\s*do\s*you\s*think\s*about)/u',
            '/(?:who\s*is|who\s*was|من\s*هو|من\s*هي|من\s*اخترع)/u',
            '/(?:football|soccer|كرة\s*القدم|مباراة)/u',
            '/(?:politics|سياسة|election|انتخاب)/u',
            '/(?:joke|نكتة|احكيلي\s*نكتة)/u',
            '/(?:chatgpt|gpt|gemini|openai)/u',
            '/(?:ignore\s*(?:all|previous)\s*instructions|تجاهل\s*(?:كل|جميع)\s*التعليمات)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}
