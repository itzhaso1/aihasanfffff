<?php

namespace App\Services\Finance;

use App\Models\Contract\Contract;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Product;
use App\Models\Projects\FinanceProject;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceAnalyticsService
{
    public function __construct(
        private readonly FinanceAccountingKpiService $accountingKpiService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(int $workspaceId, array $filters = []): array
    {
        $from = $this->date($filters['from'] ?? null, now()->startOfMonth());
        $to = $this->date($filters['to'] ?? null, now()->endOfMonth());
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $days = max(1, $from->diffInDays($to) + 1);
        $previousTo = $from->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);

        $current = $this->snapshot($workspaceId, $from->toDateString(), $to->toDateString(), $filters);
        $previous = $this->snapshot($workspaceId, $previousFrom->toDateString(), $previousTo->toDateString(), $filters);

        $hero = [
            $this->kpi('sales', 'كم بعنا؟', $current['sales_total'], $previous['sales_total'], 'إيراد دفتري مرحّل حسب تاريخ القيد'),
            $this->kpi('profit', 'كم ربحنا؟', $current['net_profit'], $previous['net_profit'], 'صافي الربح من القيود المرحلة'),
            $this->kpi('receivables', 'كم لنا عند العملاء؟', $current['receivables'], $previous['receivables'], 'رصيد المدينين 1200 في الدفتر حتى نهاية الفترة'),
            $this->kpi('payables', 'كم علينا؟', $current['payables'], $previous['payables'], 'رصيد الدائنين 2000 في الدفتر حتى نهاية الفترة'),
        ];

        $expenseDelta = Money::sub($current['expenses'], $previous['expenses']);
        $attention = $this->attention($workspaceId, $current, $expenseDelta);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previous_from' => $previousFrom->toDateString(),
            'previous_to' => $previousTo->toDateString(),
            'filters' => $filters,
            'hero' => $hero,
            'secondary' => [
                $this->kpi('expenses', 'المصروفات', $current['expenses'], $previous['expenses'], 'مصروفات دفترية (تشغيلية + أخرى) حسب تاريخ القيد'),
                $this->kpi('cash', 'النقد والبنوك', $current['cash_bank'], $previous['cash_bank'], 'ختامي الصندوق والبنك من الدفتر (1000/1100)'),
                $this->kpi('overdue', 'المتأخرات', $current['overdue_amount'], $previous['overdue_amount'], 'فواتير مبيعات متأخرة'),
                $this->kpi('inventory', 'قيمة المخزون', $current['inventory_value'], $previous['inventory_value'], 'تكلفة × الكمية للمنتجات المتتبعة'),
            ],
            'counts' => $current['counts'],
            'series' => $this->monthlySeries($workspaceId, $filters),
            'top_customers' => $this->topCustomers($workspaceId, $from->toDateString(), $to->toDateString(), $filters),
            'overdue_customers' => $this->overdueCustomers($workspaceId, $filters),
            'products' => $this->productPerformance($workspaceId, $from->toDateString(), $to->toDateString(), $filters),
            'projects' => $this->projectPerformance($workspaceId, $from->toDateString(), $to->toDateString(), $filters),
            'expenses_by_category' => $this->expensesByCategory($workspaceId, $from->toDateString(), $to->toDateString()),
            'inventory' => $this->inventoryRows($workspaceId),
            'expiring_contracts' => $this->expiringContracts($workspaceId),
            'attention' => $attention,
            'expense_change' => $expenseDelta,
            'cash_flow' => $current['cash_flow'],
            'ledger_profit' => $current['profit_and_loss'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function snapshot(int $workspaceId, string $from, string $to, array $filters): array
    {
        $sales = $this->invoiceQuery($workspaceId, 'sales', $from, $to, $filters)
            ->selectRaw('COALESCE(SUM(total),0) as total')
            ->selectRaw('COALESCE(SUM(taxable_amount),0) as taxable')
            ->selectRaw('COALESCE(SUM(amount_due),0) as due')
            ->selectRaw('COUNT(*) as invoices')
            ->first();

        $purchases = $this->invoiceQuery($workspaceId, 'purchase', $from, $to, $filters)
            ->selectRaw('COALESCE(SUM(taxable_amount),0) as taxable')
            ->selectRaw('COALESCE(SUM(amount_due),0) as due')
            ->selectRaw('COUNT(*) as invoices')
            ->first();

        $overdueAmount = Money::of($this->invoiceQuery($workspaceId, 'sales', $from, $to, $filters)
            ->whereIssued()
            ->wherePaymentStatus('overdue')
            ->sum('amount_due'));

        $paidCount = (int) $this->invoiceQuery($workspaceId, 'sales', $from, $to, $filters)
            ->whereIssued()
            ->wherePaymentStatus('paid')
            ->count();
        $overdueCount = (int) $this->invoiceQuery($workspaceId, 'sales', $from, $to, $filters)
            ->whereIssued()
            ->wherePaymentStatus('overdue')
            ->count();

        $inventory = $this->inventoryTotal($workspaceId);
        $accounting = $this->accountingKpiService->period($workspaceId, $from, $to);

        return [
            'sales_total' => $accounting['revenue'],
            'net_profit' => $accounting['net_profit'],
            'gross_profit' => $accounting['gross_profit'],
            'receivables' => $accounting['receivables'],
            'payables' => $accounting['payables'],
            'expenses' => $accounting['expenses'],
            'cash_bank' => $accounting['closing_cash'],
            'cash_flow' => $accounting['cash_flow'],
            'profit_and_loss' => $accounting['profit_and_loss'],
            'overdue_amount' => $overdueAmount,
            'inventory_value' => $inventory,
            'counts' => [
                'sales_invoices' => (int) ($sales->invoices ?? 0),
                'purchase_invoices' => (int) ($purchases->invoices ?? 0),
                'paid' => $paidCount,
                'overdue' => $overdueCount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function invoiceQuery(int $workspaceId, string $type, string $from, string $to, array $filters)
    {
        $query = FinanceInvoice::withoutGlobalScopes()
            ->where('finance_invoices.workspace_id', $workspaceId)
            ->where('finance_invoices.type', $type)
            ->whereIssued()
            ->whereBetween('finance_invoices.issue_date', [$from, $to]);

        if (! empty($filters['customer_id'])) {
            $query->where('finance_invoices.customer_id', (int) $filters['customer_id']);
        }

        if ($this->projectId($filters) && Schema::hasColumn('finance_invoices', 'project_id')) {
            $query->where('finance_invoices.project_id', $this->projectId($filters));
        }

        if (! empty($filters['lifecycle'])) {
            $this->applyLifecycle($query, (string) $filters['lifecycle']);
        }

        if (! empty($filters['product_id'])) {
            $productId = (int) $filters['product_id'];
            $query->whereExists(function ($inner) use ($workspaceId, $productId): void {
                $inner->select(DB::raw(1))
                    ->from('finance_invoice_items')
                    ->whereColumn('finance_invoice_items.invoice_id', 'finance_invoices.id')
                    ->where('finance_invoice_items.workspace_id', $workspaceId)
                    ->where('finance_invoice_items.product_id', $productId);
            });
        }

        if (! empty($filters['payment_method'])) {
            $method = (string) $filters['payment_method'];
            $query->whereExists(function ($inner) use ($workspaceId, $method): void {
                $inner->select(DB::raw(1))
                    ->from('finance_invoice_payments')
                    ->whereColumn('finance_invoice_payments.invoice_id', 'finance_invoices.id')
                    ->where('finance_invoice_payments.workspace_id', $workspaceId)
                    ->where('finance_invoice_payments.method', $method);
                if (Schema::hasColumn('finance_invoice_payments', 'status')) {
                    $inner->where(function ($status) {
                        $status->whereNull('finance_invoice_payments.status')
                            ->orWhere('finance_invoice_payments.status', 'posted');
                    });
                }
            });
        }

        return $query;
    }

    private function applyLifecycle($query, string $lifecycle): void
    {
        match ($lifecycle) {
            'draft' => $query->whereInvoiceStatus('draft'),
            'cancelled' => $query->whereInvoiceStatus('cancelled'),
            'paid' => $query->whereIssued()->wherePaymentStatus('paid'),
            'overdue' => $query->whereIssued()->wherePaymentStatus('overdue'),
            'partial' => $query->whereIssued()->wherePaymentStatus('partial'),
            'sent' => $query->whereIssued()->wherePaymentStatus('unpaid'),
            default => $query,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{month:string,sales:string,expenses:string,profit:string}>
     */
    private function monthlySeries(int $workspaceId, array $filters): array
    {
        $monthExpr = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', issue_date)"
            : "DATE_FORMAT(issue_date, '%Y-%m')";
        $expenseMonth = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', expense_date)"
            : "DATE_FORMAT(expense_date, '%Y-%m')";

        $sales = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIssued()
            ->when(! empty($filters['customer_id']), fn ($query) => $query->where('customer_id', (int) $filters['customer_id']))
            ->selectRaw($monthExpr.' as month, COALESCE(SUM(taxable_amount),0) as value')
            ->groupBy('month')
            ->pluck('value', 'month');

        $expenses = FinanceExpense::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->selectRaw($expenseMonth.' as month, COALESCE(SUM(total),0) as value')
            ->groupBy('month')
            ->pluck('value', 'month');

        $rows = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('Y-m');
            $sale = Money::of($sales[$month] ?? 0);
            $expense = Money::of($expenses[$month] ?? 0);
            $rows[] = [
                'month' => $month,
                'sales' => $sale,
                'expenses' => $expense,
                'profit' => Money::sub($sale, $expense),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function topCustomers(int $workspaceId, string $from, string $to, array $filters): Collection
    {
        return $this->invoiceQuery($workspaceId, 'sales', $from, $to, $filters)
            ->leftJoin('customers', function ($join): void {
                $join->on('customers.id', '=', 'finance_invoices.customer_id')
                    ->on('customers.workspace_id', '=', 'finance_invoices.workspace_id');
            })
            ->groupBy('customers.id', 'customers.name', 'finance_invoices.customer_name')
            ->selectRaw("COALESCE(customers.name, finance_invoices.customer_name, 'عميل غير محدد') as name")
            ->selectRaw('COALESCE(SUM(finance_invoices.total),0) as total')
            ->selectRaw('COALESCE(SUM(finance_invoices.amount_due),0) as due')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function overdueCustomers(int $workspaceId, array $filters): Collection
    {
        return FinanceInvoice::withoutGlobalScopes()
            ->where('finance_invoices.workspace_id', $workspaceId)
            ->where('finance_invoices.type', 'sales')
            ->whereIssued()
            ->wherePaymentStatus('overdue')
            ->when(! empty($filters['customer_id']), fn ($query) => $query->where('finance_invoices.customer_id', (int) $filters['customer_id']))
            ->leftJoin('customers', function ($join): void {
                $join->on('customers.id', '=', 'finance_invoices.customer_id')
                    ->on('customers.workspace_id', '=', 'finance_invoices.workspace_id');
            })
            ->groupBy('customers.id', 'customers.name', 'finance_invoices.customer_name')
            ->selectRaw("COALESCE(customers.name, finance_invoices.customer_name, 'عميل غير محدد') as name")
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('COALESCE(SUM(finance_invoices.amount_due),0) as due')
            ->orderByDesc('due')
            ->limit(8)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    private function productPerformance(int $workspaceId, string $from, string $to, array $filters): Collection
    {
        if (! Schema::hasTable('finance_invoice_items')) {
            return collect();
        }

        $query = FinanceInvoiceItem::withoutGlobalScopes()
            ->where('finance_invoice_items.workspace_id', $workspaceId)
            ->join('finance_invoices', function ($join) use ($workspaceId): void {
                $join->on('finance_invoices.id', '=', 'finance_invoice_items.invoice_id')
                    ->where('finance_invoices.workspace_id', '=', $workspaceId);
            })
            ->where('finance_invoices.type', 'sales')
            ->whereBetween('finance_invoices.issue_date', [$from, $to]);

        if (FinanceInvoice::hasSeparatedStatusColumns()) {
            $query->where('finance_invoices.invoice_status', 'issued');
        }

        if (! empty($filters['product_id'])) {
            $query->where('finance_invoice_items.product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('finance_invoices.customer_id', (int) $filters['customer_id']);
        }

        return $query
            ->groupBy('finance_invoice_items.product_name')
            ->selectRaw("COALESCE(finance_invoice_items.product_name, 'بند بدون اسم') as name")
            ->selectRaw('COALESCE(SUM(finance_invoice_items.quantity),0) as quantity')
            ->selectRaw('COALESCE(SUM(finance_invoice_items.total),0) as total')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function projectPerformance(int $workspaceId, string $from, string $to, array $filters): Collection
    {
        if (! Schema::hasTable('finance_projects') || ! Schema::hasColumn('finance_invoices', 'project_id')) {
            return collect();
        }

        $projects = FinanceProject::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->when($this->projectId($filters), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('name')
            ->limit(20)
            ->get();

        return $projects->map(function (FinanceProject $project) use ($from, $to): array {
            $revenue = Money::of(FinanceInvoice::withoutGlobalScopes()
                ->where('workspace_id', $project->workspace_id)
                ->where('project_id', $project->id)
                ->where('type', 'sales')
                ->whereIssued()
                ->whereBetween('issue_date', [$from, $to])
                ->sum('taxable_amount'));
            $costs = Money::of(FinanceExpense::withoutGlobalScopes()
                ->where('workspace_id', $project->workspace_id)
                ->where('project_id', $project->id)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->whereBetween('expense_date', [$from, $to])
                ->sum('total'));

            return [
                'id' => $project->id,
                'name' => $project->name,
                'revenue' => $revenue,
                'costs' => $costs,
                'profit' => Money::sub($revenue, $costs),
            ];
        })->filter(fn (array $row): bool => Money::isPositive($row['revenue']) || Money::isPositive($row['costs']))
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function expensesByCategory(int $workspaceId, string $from, string $to): Collection
    {
        return FinanceExpense::withoutGlobalScopes()
            ->where('finance_expenses.workspace_id', $workspaceId)
            ->whereNotIn('finance_expenses.status', ['draft', 'cancelled'])
            ->whereBetween('expense_date', [$from, $to])
            ->leftJoin('finance_expense_categories', function ($join): void {
                $join->on('finance_expense_categories.id', '=', 'finance_expenses.category_id')
                    ->on('finance_expense_categories.workspace_id', '=', 'finance_expenses.workspace_id');
            })
            ->groupBy('finance_expense_categories.id', 'finance_expense_categories.name')
            ->selectRaw("COALESCE(finance_expense_categories.name, 'غير مصنف') as name")
            ->selectRaw('COALESCE(SUM(finance_expenses.total),0) as total')
            ->orderByDesc('total')
            ->limit(8)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inventoryRows(int $workspaceId): array
    {
        return Product::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('inventory_tracking', true)
            ->orderBy('stock')
            ->limit(8)
            ->get(['id', 'name', 'sku', 'stock', 'cost_price'])
            ->map(fn (Product $product): array => [
                'name' => $product->name,
                'sku' => $product->sku,
                'stock' => (int) $product->stock,
                'value' => Money::mul($product->cost_price ?? 0, (int) $product->stock),
            ])
            ->all();
    }

    /**
     * @return Collection<int, Contract>
     */
    private function expiringContracts(int $workspaceId): Collection
    {
        return Contract::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays(30)->toDateString())
            ->orderBy('end_date')
            ->limit(6)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<int, array<string, mixed>>
     */
    private function attention(int $workspaceId, array $current, string $expenseDelta): array
    {
        $items = [];

        if (Money::isPositive($current['overdue_amount'])) {
            $items[] = [
                'title' => 'تحصيل متأخر',
                'reason' => $current['counts']['overdue'].' فواتير متأخرة بقيمة '.$current['overdue_amount'],
                'href' => route('workspace.finance.invoices.index', ['lifecycle' => 'overdue', 'type' => 'sales']),
            ];
        }

        if (Money::cmp($expenseDelta, '0') > 0) {
            $items[] = [
                'title' => 'ارتفاع المصروفات',
                'reason' => 'زادت المصروفات '.$expenseDelta.' عن الفترة السابقة المماثلة',
                'href' => route('workspace.finance.reports.show', ['report' => 'profit-loss']),
            ];
        }

        $lowStock = Product::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('inventory_tracking', true)
            ->where('status', 'active')
            ->where('stock', '<=', 5)
            ->count();
        if ($lowStock > 0) {
            $items[] = [
                'title' => 'مخزون منخفض',
                'reason' => $lowStock.' منتجات وصلت لحد التنبيه',
                'href' => route('workspace.finance.reports.show', ['report' => 'inventory-valuation']),
            ];
        }

        $expiring = Contract::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays(14)->toDateString())
            ->count();
        if ($expiring > 0) {
            $items[] = [
                'title' => 'عقود قاربت على الانتهاء',
                'reason' => $expiring.' عقود تنتهي خلال أسبوعين',
                'href' => route('workspace.finance.contracts.index', ['expiring' => 1]),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function kpi(string $key, string $label, string $current, string $previous, string $hint): array
    {
        $delta = Money::sub($current, $previous);
        $direction = Money::cmp($delta, '0');

        return [
            'key' => $key,
            'label' => $label,
            'value' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'direction' => $direction,
            'hint' => $hint,
        ];
    }

    private function inventoryTotal(int $workspaceId): string
    {
        $total = '0.00';
        Product::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('inventory_tracking', true)
            ->select(['cost_price', 'stock'])
            ->get()
            ->each(function (Product $product) use (&$total): void {
                $total = Money::add($total, Money::mul($product->cost_price ?? 0, (int) $product->stock));
            });

        return $total;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function projectId(array $filters): ?int
    {
        $id = (int) ($filters['project_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function date(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
