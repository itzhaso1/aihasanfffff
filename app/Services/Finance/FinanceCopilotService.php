<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;

class FinanceCopilotService
{
    public function __construct(
        private readonly LedgerReportService $ledgerReportService,
        private readonly PeriodComparisonService $periodComparisonService,
        private readonly BusinessAlertService $businessAlertService,
    ) {}

    /**
     * Grounded Q&A over authorized workspace finance data. Never invents amounts.
     *
     * @return array<string, mixed>
     */
    public function ask(int $workspaceId, string $question): array
    {
        $question = trim($question);
        if ($question === '') {
            return $this->answer('Ask a finance question about this workspace.', [], null);
        }

        $intent = $this->detectIntent($question);
        $now = now();

        return match ($intent) {
            'sales_this_month' => $this->salesAnswer($workspaceId, $now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'this month'),
            'net_profit' => $this->profitAnswer($workspaceId, $now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'this month'),
            'profit_drop' => $this->profitDropAnswer($workspaceId),
            'top_customers' => $this->topCustomers($workspaceId, $now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
            'overdue' => $this->overdueAnswer($workspaceId),
            'top_expenses' => $this->topExpenses($workspaceId, $now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
            'top_products' => $this->topProducts($workspaceId),
            'inventory_value' => $this->inventoryAnswer($workspaceId),
            'cash_forecast' => $this->cashForecast($workspaceId),
            'attention' => $this->attention($workspaceId),
            'create_invoice' => $this->previewOnly('Invoice creation from chat requires preview and confirmation. Use the invoice screen.'),
            default => $this->answer(
                'I can only answer from workspace finance records. Try: this month sales, net profit, overdue invoices, top customers, expenses, inventory value, or what needs attention.',
                ['intent' => 'unknown'],
                null
            ),
        };
    }

    private function detectIntent(string $question): string
    {
        $q = mb_strtolower($question);

        return match (true) {
            str_contains($q, 'أنشئ فاتورة') || str_contains($q, 'create invoice') => 'create_invoice',
            str_contains($q, 'انتباه') || str_contains($q, 'attention') => 'attention',
            str_contains($q, 'مخزون') || str_contains($q, 'inventory') => 'inventory_value',
            str_contains($q, 'تدفق') || str_contains($q, 'forecast') || str_contains($q, 'cash flow') => 'cash_forecast',
            str_contains($q, 'متأخر') || str_contains($q, 'overdue') => 'overdue',
            str_contains($q, 'أكبر العملاء') || str_contains($q, 'top customer') => 'top_customers',
            str_contains($q, 'منتجات') || str_contains($q, 'best sell') || str_contains($q, 'أكثر مبيع') => 'top_products',
            str_contains($q, 'مصروف') || str_contains($q, 'expense') => 'top_expenses',
            str_contains($q, 'نزل') || str_contains($q, 'drop') || str_contains($q, 'why') => 'profit_drop',
            str_contains($q, 'صافي') || str_contains($q, 'net profit') || str_contains($q, 'ربح') => 'net_profit',
            str_contains($q, 'مبيعات') || str_contains($q, 'sales') => 'sales_this_month',
            default => 'unknown',
        };
    }

    private function salesAnswer(int $workspaceId, Carbon $from, Carbon $to, string $label): array
    {
        $total = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIssued()
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->sum('total');

        return $this->answer(
            'Sales for '.$label.' ('.$from->toDateString().' to '.$to->toDateString().') are '.Money::of($total).'.',
            ['sales_total' => Money::of($total)],
            [$from->toDateString(), $to->toDateString()]
        );
    }

    private function profitAnswer(int $workspaceId, Carbon $from, Carbon $to, string $label): array
    {
        $pnl = $this->ledgerReportService->profitAndLoss($workspaceId, $from->toDateString(), $to->toDateString());

        return $this->answer(
            'Net profit for '.$label.' ('.$pnl['from'].' to '.$pnl['to'].') is '.$pnl['net_profit'].' based on posted ledger accounts.',
            $pnl,
            [$pnl['from'], $pnl['to']]
        );
    }

    private function profitDropAnswer(int $workspaceId): array
    {
        $compare = $this->periodComparisonService->compare($workspaceId);
        $thisMonth = $compare['this_month']['net_profit'];
        $lastMonth = $compare['last_month']['net_profit'];
        $delta = Money::sub($thisMonth, $lastMonth);
        $direction = Money::cmp($delta, '0') < 0 ? 'decreased' : 'increased';

        return $this->answer(
            'Net profit '.$direction.' from '.$lastMonth.' last month to '.$thisMonth.' this month (delta '.$delta.'). Figures come from issued sales minus purchases and expenses.',
            ['this_month' => $thisMonth, 'last_month' => $lastMonth, 'delta' => $delta],
            [$compare['last_month']['from'], $compare['this_month']['to']]
        );
    }

    private function topCustomers(int $workspaceId, Carbon $from, Carbon $to): array
    {
        $rows = FinanceInvoice::withoutGlobalScopes()
            ->where('finance_invoices.workspace_id', $workspaceId)
            ->where('finance_invoices.type', 'sales')
            ->whereIssued()
            ->whereBetween('finance_invoices.issue_date', [$from->toDateString(), $to->toDateString()])
            ->leftJoin('customers', function ($join): void {
                $join->on('customers.id', '=', 'finance_invoices.customer_id')
                    ->on('customers.workspace_id', '=', 'finance_invoices.workspace_id');
            })
            ->groupBy('customers.id', 'customers.name', 'finance_invoices.customer_name')
            ->selectRaw("COALESCE(customers.name, finance_invoices.customer_name, 'Unnamed') as customer_name")
            ->selectRaw('COALESCE(SUM(finance_invoices.total),0) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => ['name' => $row->customer_name, 'total' => Money::of($row->total)])
            ->all();

        $summary = $rows === []
            ? 'No issued sales invoices in this period.'
            : 'Top customers from '.$from->toDateString().' to '.$to->toDateString().': '.collect($rows)
                ->map(fn (array $row): string => $row['name'].' '.$row['total'])
                ->implode('; ').'.';

        return $this->answer($summary, ['customers' => $rows], [$from->toDateString(), $to->toDateString()]);
    }

    private function overdueAnswer(int $workspaceId): array
    {
        $invoices = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIssued()
            ->wherePaymentStatus('overdue')
            ->orderBy('due_date')
            ->limit(10)
            ->get(['id', 'invoice_number', 'amount_due', 'due_date']);

        $total = Money::of($invoices->sum('amount_due'));

        return $this->answer(
            $invoices->isEmpty()
                ? 'There are no overdue sales invoices in this workspace.'
                : 'Overdue invoices: '.$invoices->count().' totaling '.$total.'.',
            [
                'count' => $invoices->count(),
                'total' => $total,
                'invoices' => $invoices->map(fn (FinanceInvoice $invoice): array => [
                    'number' => $invoice->invoice_number,
                    'due' => Money::of($invoice->amount_due),
                ])->all(),
            ],
            [now()->toDateString(), now()->toDateString()]
        );
    }

    private function topExpenses(int $workspaceId, Carbon $from, Carbon $to): array
    {
        $rows = FinanceExpense::withoutGlobalScopes()
            ->where('finance_expenses.workspace_id', $workspaceId)
            ->whereNotIn('finance_expenses.status', ['draft', 'cancelled'])
            ->whereBetween('finance_expenses.expense_date', [$from->toDateString(), $to->toDateString()])
            ->leftJoin('finance_expense_categories', function ($join): void {
                $join->on('finance_expense_categories.id', '=', 'finance_expenses.category_id')
                    ->on('finance_expense_categories.workspace_id', '=', 'finance_expenses.workspace_id');
            })
            ->groupBy('finance_expense_categories.id', 'finance_expense_categories.name')
            ->selectRaw("COALESCE(finance_expense_categories.name, 'Uncategorized') as category_name")
            ->selectRaw('COALESCE(SUM(finance_expenses.total),0) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => ['name' => $row->category_name, 'total' => Money::of($row->total)])
            ->all();

        return $this->answer(
            $rows === []
                ? 'No posted expenses in this period.'
                : 'Largest expense categories: '.collect($rows)->map(fn (array $row): string => $row['name'].' '.$row['total'])->implode('; ').'.',
            ['categories' => $rows],
            [$from->toDateString(), $to->toDateString()]
        );
    }

    private function topProducts(int $workspaceId): array
    {
        $rows = FinanceInvoice::withoutGlobalScopes()
            ->where('finance_invoices.workspace_id', $workspaceId)
            ->where('finance_invoices.type', 'sales')
            ->whereIssued()
            ->join('finance_invoice_items', 'finance_invoice_items.invoice_id', '=', 'finance_invoices.id')
            ->join('products', function ($join) use ($workspaceId): void {
                $join->on('products.id', '=', 'finance_invoice_items.product_id')
                    ->where('products.workspace_id', '=', $workspaceId);
            })
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.name')
            ->selectRaw('COALESCE(SUM(finance_invoice_items.quantity),0) as qty')
            ->selectRaw('COALESCE(SUM(finance_invoice_items.total),0) as total')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name,
                'qty' => (float) $row->qty,
                'total' => Money::of($row->total),
            ])
            ->all();

        return $this->answer(
            $rows === []
                ? 'No product sales recorded on issued invoices.'
                : 'Best selling products: '.collect($rows)->map(fn (array $row): string => $row['name'].' x'.$row['qty'])->implode('; ').'.',
            ['products' => $rows],
            null
        );
    }

    private function inventoryAnswer(int $workspaceId): array
    {
        $valuation = $this->ledgerReportService->inventoryValuation($workspaceId);

        return $this->answer(
            'Tracked inventory value at cost is '.$valuation['total'].'.',
            $valuation,
            [now()->toDateString(), now()->toDateString()]
        );
    }

    private function cashForecast(int $workspaceId): array
    {
        $compare = $this->periodComparisonService->compare($workspaceId)['this_month'];
        $receivables = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIssued()
            ->sum('amount_due');
        $payables = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'purchase')
            ->whereIssued()
            ->sum('amount_due');
        $cashNow = Money::add($compare['cash'], $compare['bank']);
        $projected = Money::sub(Money::add($cashNow, $receivables), $payables);

        return $this->answer(
            'This is a projection, not a guaranteed fact. Cash+bank now '.$cashNow.', outstanding receivables '.$receivables.', outstanding payables '.$payables.', implied 90-day starting point '.$projected.'.',
            [
                'cash_now' => $cashNow,
                'receivables' => Money::of($receivables),
                'payables' => Money::of($payables),
                'projected_net' => $projected,
                'assumption' => 'All current receivables collected and all current payables paid. No new sales/expenses.',
            ],
            [now()->toDateString(), now()->copy()->addDays(90)->toDateString()]
        );
    }

    private function attention(int $workspaceId): array
    {
        $alerts = $this->businessAlertService->alerts($workspaceId);
        $text = $alerts === []
            ? 'Nothing urgent in overdue invoices, payables, low stock, discounts, or ledger balance.'
            : count($alerts).' things need attention: '.collect($alerts)->map(fn (array $alert): string => $alert['title'].' — '.$alert['reason'])->implode(' ');

        return $this->answer($text, ['alerts' => $alerts], [now()->toDateString(), now()->toDateString()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{0:string,1:string}|null  $range
     * @return array<string, mixed>
     */
    private function answer(string $text, array $data, ?array $range): array
    {
        return [
            'answer' => $text,
            'data' => $data,
            'range' => $range ? ['from' => $range[0], 'to' => $range[1]] : null,
            'preview_action' => null,
            'requires_confirmation' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewOnly(string $text): array
    {
        return [
            'answer' => $text,
            'data' => [],
            'range' => null,
            'preview_action' => 'invoice.create',
            'requires_confirmation' => true,
        ];
    }
}
