<?php

namespace App\Services\Finance\Assistant\Handlers;

final class FinancialAssistantGreetingHandler
{
    /**
     * @return array{intent: string, answer_ar: string, answer_en: string, variant: string}|null
     */
    public function match(string $question): ?array
    {
        $normalized = $this->normalize($question);
        if ($normalized === '' || mb_strlen($normalized) > 80) {
            return null;
        }

        // Greeting patterns are full-string matches (e.g. السلام عليكم). Run them
        // before financial-intent heuristics so "عليكم" is not mistaken for "كم".
        $variant = $this->detectVariant($normalized);
        if ($variant !== null) {
            return [
                'intent' => 'greeting',
                'variant' => $variant,
                ...$this->response($variant),
            ];
        }

        if ($this->containsFinancialIntent($question)) {
            return null;
        }

        return null;
    }

    private function containsFinancialIntent(string $question): bool
    {
        $q = mb_strtolower(trim($question));

        $signals = [
            'مبيعات', 'فاتورة', 'فواتير', 'ربح', 'خسارة', 'مصروف', 'مصاريف', 'عميل', 'عملاء',
            'مورد', 'موردين', 'عقد', 'عقود', 'دفع', 'مدفوع', 'متأخر', 'متاخر', 'مستحق',
            'وضع', 'وضعي', 'وضعنا', 'قديش', 'كم', 'قارن', 'compare', 'invoice', 'profit',
            'sales', 'expense', 'customer', 'supplier', 'contract', 'payment', 'overdue',
            'receivable', 'payable', 'tax', 'ضريبة', 'inv-', 'ctr-',
        ];

        foreach ($signals as $signal) {
            if ($signal === 'كم') {
                if (preg_match('/(?:^|\s)كم(?:\s|$|[؟?،,.])/u', $q) === 1) {
                    return true;
                }

                continue;
            }

            if (str_contains($q, $signal)) {
                return true;
            }
        }

        return preg_match('/\d/u', $q) === 1;
    }

    private function normalize(string $question): string
    {
        $q = mb_strtolower(trim($question));
        $q = preg_replace('/[!؟?.,،…~]+/u', '', $q) ?? $q;
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;

        return trim($q);
    }

    private function detectVariant(string $normalized): ?string
    {
        if (preg_match('/^(?:السلام\s*عليكم|سلام\s*عليكم|السلام)$/u', $normalized)) {
            return 'salam';
        }

        if (preg_match('/^(?:صباح\s*ال(?:خير|نور)|good\s*morning)$/u', $normalized)) {
            return 'morning';
        }

        if (preg_match('/^(?:مساء\s*ال(?:خير|نور)|good\s*evening)$/u', $normalized)) {
            return 'evening';
        }

        if (preg_match('/^(?:كيف\s*حالك|كيف\s*الحال|كيفك|شلونك|شلون\s*الحال|how\s*are\s*you|how\s*r\s*u)$/u', $normalized)) {
            return 'how_are_you';
        }

        if (preg_match('/^(?:شو\s*ال(?:أ|ا)خبار|شو\s*اخبارك|what(?:\'s|\s*is)\s*up|whats\s*up)$/u', $normalized)) {
            return 'whats_up';
        }

        if (preg_match('/^(?:ه+[لا]+|أ?هل+[ا]?[ين]?|أ?ه+[لا]+|مر+[ح]?ب+[ao]?|hello|hi|hey|hola)$/u', $normalized)) {
            return 'hello';
        }

        if (preg_match('/^(?:thanks|thank\s*you|شكر(?:ا|اً)|مشكور|تسلم)$/u', $normalized)) {
            return 'thanks';
        }

        return null;
    }

    /**
     * @return array{answer_ar: string, answer_en: string}
     */
    private function response(string $variant): array
    {
        return match ($variant) {
            'salam' => [
                'answer_ar' => 'وعليكم السلام 👋
أنا مساعدك المالي في HASEM، جاهز أساعدك بالمبيعات والفواتير والعملاء والموردين والعقود والمدفوعات والأرباح والتقارير.',
                'answer_en' => 'Wa alaykum assalam 👋
I am your Financial Assistant in HASEM — ready to help with sales, invoices, customers, suppliers, contracts, payments, profit, and reports.',
            ],
            'morning' => [
                'answer_ar' => 'صباح الخير ☀️
أنا مساعدك المالي في HASEM. شو حاب نراجع اليوم؟',
                'answer_en' => 'Good morning ☀️
I am your Financial Assistant in HASEM. What would you like to review today?',
            ],
            'evening' => [
                'answer_ar' => 'مساء الخير 🌙
أنا مساعدك المالي في HASEM. كيف أقدر أساعدك؟',
                'answer_en' => 'Good evening 🌙
I am your Financial Assistant in HASEM. How can I help?',
            ],
            'how_are_you' => [
                'answer_ar' => 'الحمد لله بخير 😄
شو حاب نراجع من شغلك المالي اليوم؟',
                'answer_en' => 'Doing well, thanks 😄
What would you like to review from your finances today?',
            ],
            'whats_up' => [
                'answer_ar' => 'تمام الحمد لله 👍
أقدر أعطيك ملخص الوضع المالي، المبيعات، الفواتير، أو أي سؤال عن HASEM.',
                'answer_en' => 'All good 👍
I can give you a financial summary, sales, invoices, or answer any HASEM finance question.',
            ],
            'thanks' => [
                'answer_ar' => 'العفو 🙏
إذا احتجت أي شي ثاني عن شغلك المالي، أنا هون.',
                'answer_en' => 'You are welcome 🙏
If you need anything else about your finances, I am here.',
            ],
            default => [
                'answer_ar' => 'أهلًا وسهلًا 👋
أنا مساعدك المالي في HASEM، جاهز أساعدك بالمبيعات والفواتير والعملاء والموردين والعقود والمدفوعات والأرباح والتقارير المالية.',
                'answer_en' => 'Hello 👋
I am your Financial Assistant in HASEM — ready to help with sales, invoices, customers, suppliers, contracts, payments, profit, and financial reports.',
            ],
        };
    }
}
