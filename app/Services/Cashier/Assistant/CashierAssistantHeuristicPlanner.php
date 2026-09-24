<?php

namespace App\Services\Cashier\Assistant;

final class CashierAssistantHeuristicPlanner
{
    public function __construct(
        private readonly CashierAssistantQuestionSemantics $semantics,
    ) {}

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function plan(string $question): array
    {
        $q = mb_strtolower(trim($question));

        if ($q === '') {
            return [];
        }

        if ($this->containsAny($q, ['قارن', 'compare', 'مقارنة', 'الشهر الماضي', 'last month'])) {
            return [['name' => 'sales.compare_periods', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['انتباه', 'alert', 'تحذير', 'تنبيه'])) {
            return [['name' => 'business.alerts', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['مخزون', 'inventory']) && $this->containsAny($q, ['قيمة', 'value', 'valuation'])) {
            return [['name' => 'inventory.value', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['نفاد', 'low stock', 'قرب', 'خلص', 'ينفد'])) {
            return [['name' => 'inventory.low_stock', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['صافي', 'net profit', 'ربح']) && ! $this->semantics->isProductProfitQuestion($question)) {
            $tools = [];
            if ($this->containsAny($q, ['مصروف', 'مصاريف', 'expense'])) {
                $tools[] = ['name' => 'expenses.period', 'arguments' => $this->periodArgs($question)];
            }
            if ($this->containsAny($q, ['مبيعات', 'sales', 'بيع'])) {
                $tools[] = ['name' => 'sales.period', 'arguments' => $this->periodArgs($question)];
            }
            $tools[] = ['name' => 'profit.net', 'arguments' => $this->periodArgs($question)];

            return $tools;
        }

        if ($this->containsAny($q, ['مصروف', 'مصاريف', 'expense'])) {
            return [['name' => 'expenses.period', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['كاش', 'cash', 'card', 'بطاق', 'دفع', 'payment'])) {
            return [['name' => 'sales.payment_methods', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['أكثر منتج', 'top product', 'best sell', 'أكثر مبيع', 'منتجات'])) {
            return [['name' => 'sales.top_products', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['أكثر عم', 'top customer', 'عملاء', 'customers'])) {
            return [['name' => 'sales.top_customers', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['اليوم', 'today'])) {
            return [['name' => 'sales.today', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['مبيعات', 'sales', 'بعنا', 'بيع'])) {
            return [['name' => 'sales.period', 'arguments' => $this->periodArgs($question)]];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodArgs(string $question): array
    {
        $q = mb_strtolower(trim($question));

        if (str_contains($q, 'اليوم') || str_contains($q, 'today')) {
            return ['preset' => 'today'];
        }
        if (str_contains($q, 'الأسبوع') || str_contains($q, 'week')) {
            return ['preset' => 'week'];
        }
        if (str_contains($q, 'الشهر') || str_contains($q, 'month')) {
            return ['preset' => 'month'];
        }

        $explicit = $this->semantics->extractExplicitRequestedPeriod($question);
        if ($explicit !== null) {
            return [
                'from' => $explicit['from'],
                'to' => $explicit['to'],
            ];
        }

        return ['preset' => 'month'];
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
