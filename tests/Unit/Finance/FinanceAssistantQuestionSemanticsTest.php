<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\Assistant\FinanceAssistantQuestionSemantics;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinanceAssistantQuestionSemanticsTest extends TestCase
{
    #[DataProvider('companyProfitQuestions')]
    public function test_company_profit_questions_are_not_product_profit(string $question): void
    {
        $semantics = new FinanceAssistantQuestionSemantics;

        $this->assertFalse($semantics->isProductProfitQuestion($question));
        $this->assertNull($semantics->evaluate($question));
    }

    #[DataProvider('productProfitQuestions')]
    public function test_product_profit_questions_remain_blocked(string $question): void
    {
        $semantics = new FinanceAssistantQuestionSemantics;
        $block = $semantics->evaluate($question);

        $this->assertTrue($semantics->isProductProfitQuestion($question));
        $this->assertSame('product_profit_not_available', $block['reason'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function companyProfitQuestions(): array
    {
        return [
            'why profit down this month' => ['ليش الربح هذا الشهر نازل؟'],
            'why net profit down' => ['ليش صافي الربح نازل؟'],
            'our profit vs last month' => ['ليش ربحنا أقل من الشهر الماضي؟'],
            'compare profit this month' => ['قارن الربح هذا الشهر بالشهر الماضي'],
            'why profit down no product' => ['ليش الربح نازل؟'],
            'how much profit' => ['كم الربح؟'],
            'english why profit down' => ['why is profit down this month?'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function productProfitQuestions(): array
    {
        return [
            'burger profit' => ['كم ربح البرغر؟'],
            'burger product profit' => ['كم ربح منتج البرغر؟'],
            'pizza margin' => ['كم هامش البيتزا؟'],
            'product cost and profit' => ['كم كلفة وربح المنتج X؟'],
            'named item profit' => ['كم ربح الكوكيز؟'],
            'english burger profit' => ['how much burger profit?'],
        ];
    }
}
