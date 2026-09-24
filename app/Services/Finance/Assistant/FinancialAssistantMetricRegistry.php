<?php

namespace App\Services\Finance\Assistant;

/**
 * Canonical financial metric definitions for the unified Financial Assistant.
 *
 * Prevents mixing invoice totals with ledger revenue and similar ambiguities.
 */
final class FinancialAssistantMetricRegistry
{
    public const LEDGER_REVENUE = 'ledger_revenue';

    public const INVOICE_SALES_TOTAL = 'invoice_sales_total';

    public const LEDGER_NET_PROFIT = 'ledger_net_profit';

    public const LEDGER_GROSS_PROFIT = 'ledger_gross_profit';

    public const LEDGER_COGS = 'ledger_cogs';

    public const EXPENSE_MODULE_TOTAL = 'expense_module_total';

    public const LEDGER_OPERATING_EXPENSES = 'ledger_operating_expenses';

    public const INVOICE_OUTSTANDING = 'invoice_outstanding';

    public const ACCOUNTS_RECEIVABLE = 'accounts_receivable';

    public const ACCOUNTS_PAYABLE = 'accounts_payable';

    public const PAID_PAYROLL_PERIOD = 'paid_payroll_period';

    public const INVENTORY_VALUE = 'inventory_value';

    /**
     * @return array<string, array{
     *     label_ar: string,
     *     label_en: string,
     *     description_ar: string,
     *     description_en: string,
     *     not_same_as?: list<string>
     * }>
     */
    public function definitions(): array
    {
        return [
            self::LEDGER_REVENUE => [
                'label_ar' => 'الإيراد المحاسبي',
                'label_en' => 'Ledger revenue',
                'description_ar' => 'إيراد مرحّل في الدفتر من القيود المحاسبية.',
                'description_en' => 'Posted ledger revenue from accounting entries.',
                'not_same_as' => [self::INVOICE_SALES_TOTAL],
            ],
            self::INVOICE_SALES_TOTAL => [
                'label_ar' => 'إجمالي الفواتير الصادرة',
                'label_en' => 'Issued invoice total',
                'description_ar' => 'مجموع قيم الفواتير الصادرة (قبل/بعد الدفع حسب السؤال).',
                'description_en' => 'Sum of issued invoice totals (paid/outstanding depends on the question).',
                'not_same_as' => [self::LEDGER_REVENUE],
            ],
            self::LEDGER_NET_PROFIT => [
                'label_ar' => 'صافي الربح المحاسبي',
                'label_en' => 'Ledger net profit',
                'description_ar' => 'صافي الربح من قائمة الأرباح والخسائر المرحّلة.',
                'description_en' => 'Net profit from posted profit and loss.',
            ],
            self::LEDGER_GROSS_PROFIT => [
                'label_ar' => 'إجمالي الربح المحاسبي',
                'label_en' => 'Ledger gross profit',
                'description_ar' => 'إجمالي الربح من قائمة الأرباح والخسائر.',
                'description_en' => 'Gross profit from posted profit and loss.',
            ],
            self::LEDGER_COGS => [
                'label_ar' => 'تكلفة البضاعة المباعة',
                'label_en' => 'Cost of goods sold',
                'description_ar' => 'COGS من الدفتر المحاسبي.',
                'description_en' => 'COGS from the accounting ledger.',
            ],
            self::EXPENSE_MODULE_TOTAL => [
                'label_ar' => 'مجموع المصروفات المسجلة',
                'label_en' => 'Expense-module total',
                'description_ar' => 'مصروفات مسجلة في وحدة المصروفات (ليست بالضرورة مطابقة لمصروفات P&L).',
                'description_en' => 'Expenses recorded in the expense module (not always equal to P&L operating expenses).',
                'not_same_as' => [self::LEDGER_OPERATING_EXPENSES],
            ],
            self::LEDGER_OPERATING_EXPENSES => [
                'label_ar' => 'مصروفات التشغيل المرحّلة',
                'label_en' => 'Posted operating expenses',
                'description_ar' => 'مصروفات التشغيل في الأرباح والخسائر.',
                'description_en' => 'Operating expenses in profit and loss.',
                'not_same_as' => [self::EXPENSE_MODULE_TOTAL],
            ],
            self::INVOICE_OUTSTANDING => [
                'label_ar' => 'المتبقي على الفواتير',
                'label_en' => 'Invoice outstanding',
                'description_ar' => 'المبلغ غير المدفوع على الفواتير.',
                'description_en' => 'Unpaid amount on invoices.',
            ],
            self::ACCOUNTS_RECEIVABLE => [
                'label_ar' => 'ذمم العملاء (AR)',
                'label_en' => 'Accounts receivable',
                'description_ar' => 'إجمالي المستحق على العملاء.',
                'description_en' => 'Total receivable from customers.',
            ],
            self::ACCOUNTS_PAYABLE => [
                'label_ar' => 'ذمم الموردين (AP)',
                'label_en' => 'Accounts payable',
                'description_ar' => 'إجمالي المستحق للموردين.',
                'description_en' => 'Total payable to suppliers.',
            ],
            self::PAID_PAYROLL_PERIOD => [
                'label_ar' => 'الرواتب المدفوعة',
                'label_en' => 'Paid payroll',
                'description_ar' => 'مجموع الرواتب المدفوعة خلال الفترة.',
                'description_en' => 'Total payroll paid in the period.',
            ],
            self::INVENTORY_VALUE => [
                'label_ar' => 'قيمة المخزون',
                'label_en' => 'Inventory value',
                'description_ar' => 'قيمة المخزون من السجلات المحاسبية.',
                'description_en' => 'Inventory value from accounting records.',
            ],
        ];
    }

    public function label(string $key, string $locale = 'ar'): string
    {
        $definition = $this->definitions()[$key] ?? null;
        if ($definition === null) {
            return $key;
        }

        return $locale === 'ar' ? $definition['label_ar'] : $definition['label_en'];
    }

    /**
     * @return list<string>
     */
    public function promptLines(string $locale = 'ar'): array
    {
        $lines = [];
        foreach ($this->definitions() as $key => $definition) {
            $label = $locale === 'ar' ? $definition['label_ar'] : $definition['label_en'];
            $description = $locale === 'ar' ? $definition['description_ar'] : $definition['description_en'];
            $line = "{$key}: {$label} — {$description}";

            if (! empty($definition['not_same_as'])) {
                $distinct = array_map(
                    fn (string $other): string => $this->label($other, $locale),
                    $definition['not_same_as'],
                );
                $line .= $locale === 'ar'
                    ? ' (لا يساوي: '.implode('، ', $distinct).')'
                    : ' (not the same as: '.implode(', ', $distinct).')';
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Attach human-readable metric labels to a fact payload when source is known.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function enrichFactData(array $data): array
    {
        $source = (string) ($data['source'] ?? '');
        if ($source === '' || ! isset($this->definitions()[$source])) {
            return $data;
        }

        $definition = $this->definitions()[$source];

        return array_merge($data, [
            'metric_key' => $source,
            'metric_label_ar' => $definition['label_ar'],
            'metric_label_en' => $definition['label_en'],
        ]);
    }

    /**
     * Resolve the display label for a tool result row.
     */
    public function labelForTool(string $tool, array $data, string $locale = 'ar'): string
    {
        $source = (string) ($data['source'] ?? '');
        if ($source !== '' && isset($this->definitions()[$source])) {
            return $this->label($source, $locale);
        }

        $metricKind = (string) ($data['expenses_metric_kind'] ?? $data['payroll_metric_kind'] ?? '');
        if ($metricKind !== '' && isset($this->definitions()[$metricKind])) {
            return $this->label($metricKind, $locale);
        }

        return match ($tool) {
            'sales.today', 'sales.period' => $this->label(self::LEDGER_REVENUE, $locale),
            'expenses.period' => $locale === 'ar'
                ? (string) ($data['label_ar'] ?? $this->label(self::EXPENSE_MODULE_TOTAL, $locale))
                : (string) ($data['label_en'] ?? $this->label(self::EXPENSE_MODULE_TOTAL, $locale)),
            'profit.net' => $this->label(self::LEDGER_NET_PROFIT, $locale),
            'profit.gross' => $this->label(self::LEDGER_GROSS_PROFIT, $locale),
            'profit.cogs' => $this->label(self::LEDGER_COGS, $locale),
            'payroll.period', 'payroll.total' => $locale === 'ar'
                ? (string) ($data['label_ar'] ?? $this->label(self::PAID_PAYROLL_PERIOD, $locale))
                : (string) ($data['label_en'] ?? $this->label(self::PAID_PAYROLL_PERIOD, $locale)),
            'payroll.employees_count' => $locale === 'ar'
                ? (string) ($data['label_ar'] ?? 'موظفو الشركة النشطون')
                : (string) ($data['label_en'] ?? 'Active company employees'),
            'suppliers.payable' => $this->label(self::ACCOUNTS_PAYABLE, $locale),
            'inventory.value' => $this->label(self::INVENTORY_VALUE, $locale),
            'finance.summary' => $locale === 'ar' ? 'ملخص مالي' : 'Financial summary',
            default => $tool,
        };
    }
}
