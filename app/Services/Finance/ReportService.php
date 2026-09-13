<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;

class ReportService
{
    /**
     * @return array<string,mixed>
     */
    public function summary(string $from, string $to): array
    {
        $salesSummary = FinanceInvoice::query()
            ->where('type', 'sales')
            ->whereIssued()
            ->whereBetween('issue_date', [$from, $to])
            ->selectRaw('COUNT(*) as invoices_count')
            ->selectRaw('COALESCE(SUM(total), 0) as total_sales')
            ->selectRaw('COALESCE(SUM(amount_paid), 0) as total_paid')
            ->selectRaw('COALESCE(SUM(amount_due), 0) as total_due')
            ->first();

        $purchaseSummary = FinanceInvoice::query()
            ->where('type', 'purchase')
            ->whereIssued()
            ->whereBetween('issue_date', [$from, $to])
            ->selectRaw('COUNT(*) as invoices_count')
            ->selectRaw('COALESCE(SUM(total), 0) as total_purchases')
            ->selectRaw('COALESCE(SUM(amount_due), 0) as total_due')
            ->first();

        $expenseSummary = FinanceExpense::query()
            ->whereBetween('expense_date', [$from, $to])
            ->selectRaw('COUNT(*) as expenses_count')
            ->selectRaw('COALESCE(SUM(total), 0) as total_expenses')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as total_vat')
            ->first();

        $salesByCustomer = FinanceInvoice::query()
            ->where('type', 'sales')
            ->whereIssued()
            ->whereBetween('issue_date', [$from, $to])
            ->leftJoin('customers', function ($join): void {
                $join->on('customers.id', '=', 'finance_invoices.customer_id')
                    ->on('customers.workspace_id', '=', 'finance_invoices.workspace_id');
            })
            ->groupBy('customers.id', 'customers.name', 'finance_invoices.customer_name')
            ->selectRaw('customers.id as customer_id')
            ->selectRaw("COALESCE(customers.name, finance_invoices.customer_name, 'عميل غير محدد') as customer_name")
            ->selectRaw('COALESCE(SUM(finance_invoices.total), 0) as total')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $expensesByCategory = FinanceExpense::query()
            ->whereBetween('expense_date', [$from, $to])
            ->leftJoin('finance_expense_categories', function ($join): void {
                $join->on('finance_expense_categories.id', '=', 'finance_expenses.category_id')
                    ->on('finance_expense_categories.workspace_id', '=', 'finance_expenses.workspace_id');
            })
            ->groupBy('finance_expense_categories.id', 'finance_expense_categories.name')
            ->selectRaw("COALESCE(finance_expense_categories.name, 'غير مصنف') as category_name")
            ->selectRaw('COALESCE(SUM(finance_expenses.total), 0) as total')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $outputVat = (float) FinanceInvoice::query()
            ->where('type', 'sales')
            ->whereIssued()
            ->whereBetween('issue_date', [$from, $to])
            ->sum('tax_amount');
        $inputVatPurchase = (float) FinanceInvoice::query()
            ->where('type', 'purchase')
            ->whereIssued()
            ->whereBetween('issue_date', [$from, $to])
            ->sum('tax_amount');
        $inputVatExpense = (float) FinanceExpense::query()
            ->whereBetween('expense_date', [$from, $to])
            ->sum('tax_amount');

        $noteVat = $this->issuedNoteVatByInvoiceType($from, $to);
        $outputVat += $noteVat['sales'];
        $inputVatPurchase += $noteVat['purchase'];
        $inputVat = $inputVatPurchase + $inputVatExpense;

        $noteTotals = $this->issuedNoteTotalsByInvoiceType($from, $to);
        if ($salesSummary) {
            $salesSummary->total_sales = round((float) $salesSummary->total_sales + $noteTotals['sales'], 2);
        }
        if ($purchaseSummary) {
            $purchaseSummary->total_purchases = round((float) $purchaseSummary->total_purchases + $noteTotals['purchase'], 2);
        }

        $noteByCustomer = $this->issuedNoteTotalsByCustomer($from, $to);
        $salesByCustomer = $salesByCustomer->map(function ($row) use ($noteByCustomer) {
            $customerId = (int) ($row->customer_id ?? 0);
            if ($customerId > 0 && array_key_exists($customerId, $noteByCustomer)) {
                $row->total = round((float) $row->total + $noteByCustomer[$customerId], 2);
            }

            return $row;
        });

        return [
            'salesSummary' => $salesSummary,
            'purchaseSummary' => $purchaseSummary,
            'expenseSummary' => $expenseSummary,
            'salesByCustomer' => $salesByCustomer,
            'expensesByCategory' => $expensesByCategory,
            'vat' => [
                'output' => round($outputVat, 2),
                'input' => round($inputVat, 2),
                'net' => round($outputVat - $inputVat, 2),
            ],
        ];
    }

    /**
     * Issued credit notes reduce VAT; issued debit notes increase it.
     * Draft/cancelled notes are excluded. Invoice tax_amount is never mutated,
     * so notes are applied once here and are not double-counted.
     *
     * @return array{sales: float, purchase: float}
     */
    private function issuedNoteVatByInvoiceType(string $from, string $to): array
    {
        $adjustments = ['sales' => 0.0, 'purchase' => 0.0];

        $rows = FinanceCreditNote::query()
            ->selectRaw('finance_invoices.type as invoice_type')
            ->selectRaw('finance_credit_notes.type as note_type')
            ->selectRaw('COALESCE(SUM(finance_credit_notes.tax_amount), 0) as tax_total')
            ->join('finance_invoices', function ($join): void {
                $join->on('finance_invoices.id', '=', 'finance_credit_notes.invoice_id')
                    ->on('finance_invoices.workspace_id', '=', 'finance_credit_notes.workspace_id');
            })
            ->where('finance_credit_notes.status', FinanceCreditNote::STATUS_ISSUED)
            ->whereBetween('finance_credit_notes.issue_date', [$from, $to])
            ->groupBy('finance_invoices.type', 'finance_credit_notes.type')
            ->get();

        foreach ($rows as $row) {
            $invoiceType = (string) $row->invoice_type;
            if (! array_key_exists($invoiceType, $adjustments)) {
                continue;
            }

            $tax = (float) $row->tax_total;
            $adjustments[$invoiceType] += ((string) $row->note_type) === FinanceCreditNote::TYPE_CREDIT
                ? -$tax
                : $tax;
        }

        return $adjustments;
    }

    /**
     * Issued credit notes reduce sales/purchase totals; issued debit notes increase them.
     *
     * @return array{sales: float, purchase: float}
     */
    private function issuedNoteTotalsByInvoiceType(string $from, string $to): array
    {
        $adjustments = ['sales' => 0.0, 'purchase' => 0.0];

        $rows = FinanceCreditNote::query()
            ->selectRaw('finance_invoices.type as invoice_type')
            ->selectRaw('finance_credit_notes.type as note_type')
            ->selectRaw('COALESCE(SUM(finance_credit_notes.total), 0) as note_total')
            ->join('finance_invoices', function ($join): void {
                $join->on('finance_invoices.id', '=', 'finance_credit_notes.invoice_id')
                    ->on('finance_invoices.workspace_id', '=', 'finance_credit_notes.workspace_id');
            })
            ->where('finance_credit_notes.status', FinanceCreditNote::STATUS_ISSUED)
            ->whereBetween('finance_credit_notes.issue_date', [$from, $to])
            ->groupBy('finance_invoices.type', 'finance_credit_notes.type')
            ->get();

        foreach ($rows as $row) {
            $invoiceType = (string) $row->invoice_type;
            if (! array_key_exists($invoiceType, $adjustments)) {
                continue;
            }

            $total = (float) $row->note_total;
            $adjustments[$invoiceType] += ((string) $row->note_type) === FinanceCreditNote::TYPE_CREDIT
                ? -$total
                : $total;
        }

        return $adjustments;
    }

    /**
     * @return array<int, float>
     */
    private function issuedNoteTotalsByCustomer(string $from, string $to): array
    {
        $adjustments = [];

        $rows = FinanceCreditNote::query()
            ->selectRaw('finance_credit_notes.customer_id as customer_id')
            ->selectRaw('finance_credit_notes.type as note_type')
            ->selectRaw('COALESCE(SUM(finance_credit_notes.total), 0) as note_total')
            ->join('finance_invoices', function ($join): void {
                $join->on('finance_invoices.id', '=', 'finance_credit_notes.invoice_id')
                    ->on('finance_invoices.workspace_id', '=', 'finance_credit_notes.workspace_id');
            })
            ->where('finance_credit_notes.status', FinanceCreditNote::STATUS_ISSUED)
            ->where('finance_invoices.type', 'sales')
            ->whereBetween('finance_credit_notes.issue_date', [$from, $to])
            ->whereNotNull('finance_credit_notes.customer_id')
            ->groupBy('finance_credit_notes.customer_id', 'finance_credit_notes.type')
            ->get();

        foreach ($rows as $row) {
            $customerId = (int) $row->customer_id;
            $total = (float) $row->note_total;
            $delta = ((string) $row->note_type) === FinanceCreditNote::TYPE_CREDIT ? -$total : $total;
            $adjustments[$customerId] = ($adjustments[$customerId] ?? 0.0) + $delta;
        }

        return $adjustments;
    }
}
