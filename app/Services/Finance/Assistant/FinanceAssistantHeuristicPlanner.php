<?php

namespace App\Services\Finance\Assistant;

use App\Services\Finance\Assistant\Conversation\ConversationEntityState;
use App\Services\Finance\Assistant\Conversation\ConversationEntityTracker;

/**
 * @deprecated Compatibility planner used only when the Gemini agent cannot run.
 */
final class FinanceAssistantHeuristicPlanner
{
    public function __construct(
        private readonly FinanceAssistantQuestionSemantics $semantics,
        private readonly ConversationEntityTracker $entityTracker,
    ) {}

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function plan(string $question, ?ConversationEntityState $context = null): array
    {
        if ($context !== null && ! $context->isEmpty()) {
            $followUp = $this->entityTracker->resolveFollowUpPlans($question, $context);
            if ($followUp !== null) {
                return $followUp;
            }
        }

        $plans = $this->planCore($question);

        if ($context !== null && $plans !== []) {
            return $this->entityTracker->enrichPlans($plans, $context);
        }

        return $plans;
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function planCore(string $question): array
    {
        $q = mb_strtolower(trim($question));

        if ($q === '') {
            return [];
        }

        if ($this->containsAny($q, ['قارن', 'compare', 'مقارنة', 'الشهر الماضي', 'last month', 'الأسبوع الماضي'])) {
            $preset = $this->containsAny($q, ['أسبوع', 'week']) ? 'week' : 'month';

            return [['name' => 'compare.periods', 'arguments' => ['preset' => $preset]]];
        }

        if ($this->containsAny($q, [
            'ملخص', 'summary', 'وضع الشركة', 'الوضع المالي', 'وضعي', 'وضعنا', 'وضعك',
            'شو وضع', 'كيف وضع', 'how are we', 'how is business',
        ])) {
            return [['name' => 'finance.summary', 'arguments' => $this->periodArgs($question)]];
        }

        if (preg_match('/(?:ctr[- ]?\d+|ctr\d+)/i', $question) === 1
            || (preg_match('/\d{3,}/', $question) === 1 && $this->containsAny($q, ['عقد', 'contract', 'ctr']))) {
            return [['name' => 'contracts.details', 'arguments' => [
                'contract_number' => $this->extractContractNumber($question),
            ]]];
        }

        if ($this->containsAny($q, ['دفعة قادمة', 'upcoming', 'next billing', 'جدول فوترة', 'billing schedule'])) {
            return [['name' => 'billing_schedules.upcoming', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['غير مفوتر', 'unbilled', 'لم نفوتر', 'ما فوترنا'])) {
            $args = $this->contractArgs($question);

            return [['name' => 'contracts.unbilled', 'arguments' => $args]];
        }

        if ($this->containsAny($q, ['عقد', 'contract']) && $this->containsAny($q, ['فوتر', 'فاتور', 'invoice', 'باقي', 'balance', 'دفع', 'paid'])) {
            $args = $this->contractArgs($question);

            return [['name' => 'contracts.balance', 'arguments' => $args]];
        }

        if ($this->containsAny($q, ['عقد', 'contract']) && $this->containsAny($q, ['جدول', 'schedule', 'قسط', 'installment'])) {
            $args = $this->contractArgs($question);

            return [['name' => 'contracts.schedules', 'arguments' => $args]];
        }

        if ($this->containsAny($q, ['عقد', 'contract']) && $this->containsAny($q, ['فاتورة', 'فواتير', 'invoice'])) {
            $args = $this->contractArgs($question);

            return [['name' => 'contracts.invoices', 'arguments' => $args]];
        }

        if ($this->containsAny($q, ['عندي عقد', 'عقد مع', 'contract with']) || ($this->containsAny($q, ['عقد', 'contract']) && ($customerName = $this->extractCustomerName($question)))) {
            $args = $this->contractArgs($question);
            if ($args === [] && ($customerName ?? null)) {
                $args = ['customer_name' => $customerName];
            }

            return [['name' => 'contracts.lookup', 'arguments' => $args]];
        }

        if (preg_match('/(?:inv[- ]?\d+|inv\d+)/i', $question) === 1
            || (preg_match('/\d{3,}/', $question) === 1 && $this->containsAny($q, ['فاتورة', 'invoice', 'inv']))) {
            return [['name' => 'invoices.details', 'arguments' => [
                'invoice_number' => $this->extractInvoiceNumber($question),
            ]]];
        }

        if ($this->containsAny($q, ['متأخر', 'متاخر', 'overdue']) && $this->containsAny($q, ['فاتورة', 'فواتير', 'invoice'])) {
            if ($customerName = $this->extractCustomerName($question)) {
                return [['name' => 'customers.overdue', 'arguments' => ['customer_name' => $customerName]]];
            }

            return [['name' => 'invoices.overdue', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['فواتير', 'invoices']) && ($customerName = $this->extractCustomerName($question))) {
            return [['name' => 'invoices.by_customer', 'arguments' => ['customer_name' => $customerName]]];
        }

        if ($this->containsAny($q, ['عليه', 'علي', 'مستحق', 'balance', 'رصيد', 'باقي']) && ($customerName = $this->extractCustomerName($question))) {
            return [['name' => 'customers.balance', 'arguments' => ['customer_name' => $customerName]]];
        }

        if ($this->containsAny($q, ['كشف', 'statement']) && ($customerName = $this->extractCustomerName($question))) {
            return [['name' => 'customers.statement', 'arguments' => [
                'customer_name' => $customerName,
                ...$this->periodArgs($question),
            ]]];
        }

        if ($this->containsAny($q, ['عدد', 'count']) && $this->containsAny($q, ['فاتورة', 'فواتير', 'invoice'])) {
            return [['name' => 'invoices.count', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['فاتورة', 'فواتير', 'invoice']) && $this->containsAny($q, ['قيمة', 'مجموع', 'total', 'summary'])) {
            return [['name' => 'invoices.summary', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['انتباه', 'alert', 'تحذير', 'تنبيه'])) {
            return [['name' => 'finance.alerts', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['مورد', 'supplier', 'payable', 'مستحق', 'علينا'])) {
            return [['name' => 'suppliers.payable', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['مخزون', 'inventory']) && $this->containsAny($q, ['قيمة', 'value', 'valuation'])) {
            return [['name' => 'inventory.value', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['cogs', 'تكلفة البضاعة', 'كلفتنا', 'كلفة']) && ! $this->containsAny($q, ['منتج', 'product'])) {
            return [['name' => 'profit.cogs', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['موظف', 'موظفين', 'موظفون', 'employee', 'employees', 'staff', 'عاملين'])
            && $this->containsAny($q, ['كم', 'عدد', 'how many', 'count'])) {
            return [['name' => 'payroll.employees_count', 'arguments' => []]];
        }

        if ($this->containsAny($q, ['رواتب', 'payroll', 'راتب'])) {
            return [['name' => 'payroll.period', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['أكثر عم', 'top customer', 'عملاء', 'customers'])) {
            return [['name' => 'customers.top', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['أكثر منتج', 'top product', 'best sell', 'أكثر مبيع', 'منتجات'])) {
            return [['name' => 'products.top', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['إجمالي الربح', 'gross profit', 'مجمل'])) {
            return [['name' => 'profit.gross', 'arguments' => $this->periodArgs($question)]];
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

        if ($this->containsAny($q, ['مصروف', 'مصاريف', 'expense']) && $this->containsAny($q, ['فئة', 'فئات', 'بند', 'بنود', 'category'])) {
            return [['name' => 'expenses.by_category', 'arguments' => $this->periodArgs($question)]];
        }

        if ($this->containsAny($q, ['مصروف', 'مصاريف', 'expense', 'قديش'])) {
            return [['name' => 'expenses.period', 'arguments' => $this->periodArgs($question)]];
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
        if (str_contains($q, 'الشهر الماضي') || str_contains($q, 'last month')) {
            return ['preset' => 'last_month'];
        }
        if (str_contains($q, 'الشهر') || str_contains($q, 'month') || str_contains($q, 'هالشهر')) {
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
     * @return array<string, mixed>
     */
    private function contractArgs(string $question): array
    {
        if (preg_match('/(?:ctr[- ]?)(\d+)/i', $question, $matches) === 1) {
            return ['contract_number' => 'CTR-'.$matches[1]];
        }

        if ($customerName = $this->extractCustomerName($question)) {
            return ['customer_name' => $customerName];
        }

        return [];
    }

    private function extractContractNumber(string $question): string
    {
        if (preg_match('/(?:ctr[- ]?)(\d+)/i', $question, $matches) === 1) {
            return 'CTR-'.$matches[1];
        }

        if (preg_match('/(\d{3,})/', $question, $matches) === 1) {
            return $matches[1];
        }

        return trim($question);
    }

    private function extractInvoiceNumber(string $question): string
    {
        if (preg_match('/(?:inv[- ]?)(\d+)/i', $question, $matches) === 1) {
            return 'INV-'.$matches[1];
        }

        if (preg_match('/(\d{3,})/', $question, $matches) === 1) {
            return $matches[1];
        }

        return trim($question);
    }

    private function extractCustomerName(string $question): ?string
    {
        $patterns = [
            '/(?:فواتير|فاتورة|invoices?)\s+(?:ل|لـ|ل\s)?([\p{L}\s]{2,40})/u',
            '/(?:عميل|customer)\s+(?:اسمه\s)?([\p{L}\s]{2,40})/u',
            '/(?:رصيد|balance)\s+عميل\s+([\p{L}\s]{2,40})/u',
            '/(?:كم\s+)?(?:عليه|عنده|مستحق)\s+([\p{L}\s]{2,40})/u',
            '/(?:محمد|[\p{L}]{2,20})\s+(?:عليه|مستحق)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $question, $matches) === 1) {
                $name = trim($matches[1] ?? $matches[0]);
                $name = preg_replace('/\s*(?:عليه|مستحق|فواتير|فاتورة).*$/u', '', $name) ?? $name;

                return trim($name) !== '' ? trim($name) : null;
            }
        }

        if (preg_match('/^([\p{L}]{2,20})$/u', trim($question), $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
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
