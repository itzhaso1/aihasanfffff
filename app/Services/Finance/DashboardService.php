<?php

namespace App\Services\Finance;

use App\Models\Contract\Contract;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinancePayrollAdjustment;
use App\Models\Finance\FinanceSalaryAdvance;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly FinanceAccountingKpiService $accountingKpiService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function metrics(): array
    {
        $salesInvoices = FinanceInvoice::query()->where('type', 'sales')->whereIssued();
        $purchaseInvoices = FinanceInvoice::query()->where('type', 'purchase')->whereIssued();
        $postedExpenses = FinanceExpense::query()->whereNotIn('status', ['draft', 'cancelled']);

        $totalPurchases = (float) (clone $purchaseInvoices)->sum('total');
        $outputVat = (float) (clone $salesInvoices)->sum('tax_amount');
        $inputVat = (float) (
            (clone $purchaseInvoices)->sum('tax_amount')
            + (clone $postedExpenses)->sum('tax_amount')
        );
        $unpaidInvoices = (int) (clone $salesInvoices)
            ->whereIssued()
            ->where(function ($builder): void {
                $builder->where(function ($stateQuery): void {
                    $stateQuery->wherePaymentStatus('unpaid');
                })->orWhere(function ($stateQuery): void {
                    $stateQuery->wherePaymentStatus('partial');
                })->orWhere(function ($stateQuery): void {
                    $stateQuery->wherePaymentStatus('overdue');
                });
            })
            ->count();
        $overdueInvoices = (int) (clone $salesInvoices)
            ->whereIssued()
            ->wherePaymentStatus('overdue')
            ->count();

        $cashBalance = (float) FinanceTreasuryAccount::query()->where('type', 'cash')->sum('current_balance');
        $bankBalance = (float) FinanceTreasuryAccount::query()->where('type', 'bank')->sum('current_balance');
        $companyEmployees = (int) FinanceEmployee::query()->where('status', 'active')->count();
        $payrollPaidTotal = (float) FinanceEmployeePayrollRecord::query()
            ->where('payment_status', 'paid')
            ->sum('net_amount');
        $allowancesAndBonusesTotal = (float) FinancePayrollAdjustment::query()
            ->whereIn('type', ['allowance', 'bonus'])
            ->where('status', 'posted')
            ->sum('amount');
        $deductionsTotal = (float) FinancePayrollAdjustment::query()
            ->where('type', 'deduction')
            ->where('status', 'posted')
            ->sum('amount');
        $openAdvancesTotal = (float) FinanceSalaryAdvance::query()
            ->where('status', 'open')
            ->sum('remaining_amount');
        $activeContractsCount = (int) Contract::query()
            ->where('status', 'open')
            ->count();

        $workspaceId = $this->workspaceContext->workspaceId();
        $accounting = $workspaceId
            ? $this->accountingKpiService->period((int) $workspaceId, FinanceAccountingKpiService::LIFETIME_FROM, now()->toDateString())
            : null;

        return [
            'cards' => [
                'sales_total' => $accounting['revenue'] ?? '0.00',
                'purchases_total' => Money::of($totalPurchases),
                'expenses_total' => $accounting['expenses'] ?? '0.00',
                'net_profit' => $accounting['net_profit'] ?? '0.00',
                'receivables_total' => $accounting['receivables'] ?? '0.00',
                'payables_total' => $accounting['payables'] ?? '0.00',
                'unpaid_invoices' => $unpaidInvoices,
                'overdue_invoices' => $overdueInvoices,
                'output_vat' => round($outputVat, 2),
                'input_vat' => round($inputVat, 2),
                'net_vat' => round($outputVat - $inputVat, 2),
                'cash_balance' => round($cashBalance, 2),
                'bank_balance' => round($bankBalance, 2),
                'company_employees' => $companyEmployees,
                'payroll_paid_total' => round($payrollPaidTotal, 2),
                'allowances_bonuses_total' => round($allowancesAndBonusesTotal, 2),
                'deductions_total' => round($deductionsTotal, 2),
                'open_advances_total' => round($openAdvancesTotal, 2),
                'active_contracts_count' => $activeContractsCount,
            ],
            'charts' => [
                'sales' => $this->monthlyIssuedInvoiceSeries('total', 'sales'),
                'expenses' => $this->monthlyPostedExpenseSeries('total'),
                'profit' => $this->profitSeries(),
                'vat' => $this->vatSeries(),
                'cash_flow' => $this->cashFlowSeries(),
            ],
            'latest' => [
                'invoices' => FinanceInvoice::query()->with(['customer', 'supplier'])->latest('id')->limit(10)->get(),
                'payments' => FinanceInvoicePayment::query()->with(['invoice.customer', 'receipt', 'treasuryAccount'])->latest('id')->limit(10)->get(),
                'expenses' => FinanceExpense::query()->with(['supplier', 'category', 'treasuryAccount'])->latest('id')->limit(10)->get(),
                'overdue_invoices' => FinanceInvoice::query()
                    ->with('customer')
                    ->where('type', 'sales')
                    ->whereIssued()
                    ->wherePaymentStatus('overdue')
                    ->latest('due_date')
                    ->limit(10)
                    ->get(),
            ],
            'tax_rates' => FinanceTaxRate::query()->where('is_active', true)->orderByDesc('is_default')->get(),
        ];
    }

    /**
     * @return array<int, array{month:string,value:float}>
     */
    private function monthlyIssuedInvoiceSeries(string $sumColumn, string $type): array
    {
        $workspaceId = $this->workspaceContext->workspaceId();
        if (! $workspaceId) {
            return [];
        }

        $dateColumn = Schema::hasColumn('finance_invoices', 'issue_date') ? 'issue_date' : 'created_at';
        $monthExpr = $this->monthExpression($dateColumn);

        $query = DB::table('finance_invoices')
            ->selectRaw($monthExpr.' as month, SUM('.$sumColumn.') as value')
            ->where('workspace_id', $workspaceId)
            ->where('type', $type)
            ->whereDate($dateColumn, '>=', now()->subMonths(11)->startOfMonth()->toDateString());

        if (Schema::hasColumn('finance_invoices', 'invoice_status')) {
            $query->where('invoice_status', 'issued');
        } else {
            $query->whereNotIn('status', ['draft', 'cancelled']);
        }

        if (Schema::hasColumn('finance_invoices', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $this->filledMonths(
            $query->groupBy('month')->orderBy('month')->get()
        );
    }

    /**
     * @return array<int, array{month:string,value:float}>
     */
    private function monthlyPostedExpenseSeries(string $sumColumn): array
    {
        $workspaceId = $this->workspaceContext->workspaceId();
        if (! $workspaceId) {
            return [];
        }

        $dateColumn = Schema::hasColumn('finance_expenses', 'expense_date') ? 'expense_date' : 'created_at';
        $monthExpr = $this->monthExpression($dateColumn);

        $query = DB::table('finance_expenses')
            ->selectRaw($monthExpr.' as month, SUM('.$sumColumn.') as value')
            ->where('workspace_id', $workspaceId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereDate($dateColumn, '>=', now()->subMonths(11)->startOfMonth()->toDateString());

        return $this->filledMonths(
            $query->groupBy('month')->orderBy('month')->get()
        );
    }

    /**
     * @return array<int, array{month:string,value:float}>
     */
    private function monthlyPostedPaymentSeries(): array
    {
        $workspaceId = $this->workspaceContext->workspaceId();
        if (! $workspaceId) {
            return [];
        }

        $dateColumn = Schema::hasColumn('finance_invoice_payments', 'payment_date') ? 'payment_date' : 'created_at';
        $monthExpr = $this->monthExpression($dateColumn);

        $query = DB::table('finance_invoice_payments')
            ->selectRaw($monthExpr.' as month, SUM(amount) as value')
            ->where('workspace_id', $workspaceId)
            ->whereDate($dateColumn, '>=', now()->subMonths(11)->startOfMonth()->toDateString());

        if (Schema::hasColumn('finance_invoice_payments', 'status')) {
            $query->where(function ($inner): void {
                $inner->where('status', FinanceInvoicePayment::STATUS_POSTED)
                    ->orWhereNull('status')
                    ->orWhere('status', '');
            });
        }

        return $this->filledMonths(
            $query->groupBy('month')->orderBy('month')->get()
        );
    }

    private function monthExpression(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    /**
     * @return array<int, array{month:string,value:float}>
     */
    private function profitSeries(): array
    {
        $sales = collect($this->monthlyIssuedInvoiceSeries('taxable_amount', 'sales'))
            ->keyBy('month');
        $purchases = collect($this->monthlyIssuedInvoiceSeries('taxable_amount', 'purchase'))
            ->keyBy('month');
        $expenses = collect($this->monthlyPostedExpenseSeries('total'))
            ->keyBy('month');

        $months = $this->last12Months();

        return collect($months)->map(function (string $month) use ($sales, $purchases, $expenses): array {
            $salesValue = (float) ($sales[$month]['value'] ?? 0);
            $purchaseValue = (float) ($purchases[$month]['value'] ?? 0);
            $expenseValue = (float) ($expenses[$month]['value'] ?? 0);

            return [
                'month' => $month,
                'value' => round($salesValue - $purchaseValue - $expenseValue, 2),
            ];
        })->all();
    }

    /**
     * @return array<int, array{month:string,value:float}>
     */
    private function vatSeries(): array
    {
        $output = collect($this->monthlyIssuedInvoiceSeries('tax_amount', 'sales'))
            ->keyBy('month');
        $inputFromPurchase = collect($this->monthlyIssuedInvoiceSeries('tax_amount', 'purchase'))
            ->keyBy('month');
        $inputFromExpense = collect($this->monthlyPostedExpenseSeries('tax_amount'))
            ->keyBy('month');
        $months = $this->last12Months();

        return collect($months)->map(function (string $month) use ($output, $inputFromPurchase, $inputFromExpense): array {
            $outputValue = (float) ($output[$month]['value'] ?? 0);
            $inputValue = (float) ($inputFromPurchase[$month]['value'] ?? 0) + (float) ($inputFromExpense[$month]['value'] ?? 0);

            return [
                'month' => $month,
                'value' => round($outputValue - $inputValue, 2),
            ];
        })->all();
    }

    /**
     * @return array<int, array{month:string,value:float}>
     */
    private function cashFlowSeries(): array
    {
        $inflow = collect($this->monthlyPostedPaymentSeries())->keyBy('month');
        $outflow = collect($this->monthlyPostedExpenseSeries('total'))->keyBy('month');
        $months = $this->last12Months();

        return collect($months)->map(function (string $month) use ($inflow, $outflow): array {
            $inflowValue = (float) ($inflow[$month]['value'] ?? 0);
            $outflowValue = (float) ($outflow[$month]['value'] ?? 0);

            return [
                'month' => $month,
                'value' => round($inflowValue - $outflowValue, 2),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array{month:string,value:float}>
     */
    private function filledMonths(Collection $rows): array
    {
        $map = $rows->mapWithKeys(function ($row): array {
            return [(string) $row->month => round((float) $row->value, 2)];
        });

        return collect($this->last12Months())->map(function (string $month) use ($map): array {
            return [
                'month' => $month,
                'value' => round((float) ($map[$month] ?? 0), 2),
            ];
        })->all();
    }

    /**
     * @return array<int, string>
     */
    private function last12Months(): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->format('Y-m');
        }

        return $months;
    }
}
