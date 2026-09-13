<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceJournalEntryLine;
use App\Models\Product;
use App\Support\Money\Money;

class BusinessAlertService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function alerts(int $workspaceId): array
    {
        $alerts = [];

        $overdue = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIssued()
            ->wherePaymentStatus('overdue')
            ->count();
        if ($overdue > 0) {
            $alerts[] = [
                'key' => 'overdue_invoices',
                'severity' => 'high',
                'title' => 'فواتير متأخرة',
                'reason' => $overdue.' invoices are overdue.',
                'count' => $overdue,
                'action' => 'Review receivables and send reminders.',
            ];
        }

        $unpaidBills = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'purchase')
            ->whereIssued()
            ->where('amount_due', '>', 0)
            ->count();
        if ($unpaidBills > 0) {
            $alerts[] = [
                'key' => 'unpaid_supplier_bills',
                'severity' => 'medium',
                'title' => 'فواتير موردين غير مسددة',
                'reason' => $unpaidBills.' supplier bills still have an outstanding balance.',
                'count' => $unpaidBills,
                'action' => 'Review payables and schedule payments.',
            ];
        }

        $lowStock = Product::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('inventory_tracking', true)
            ->where('status', 'active')
            ->where('stock', '<=', 5)
            ->count();
        if ($lowStock > 0) {
            $alerts[] = [
                'key' => 'low_stock',
                'severity' => 'medium',
                'title' => 'مخزون منخفض',
                'reason' => $lowStock.' tracked products are at or below 5 units.',
                'count' => $lowStock,
                'action' => 'Review replenishment or purchase orders.',
            ];
        }

        $imbalance = $this->ledgerImbalance($workspaceId);
        if ($imbalance) {
            $alerts[] = [
                'key' => 'accounting_imbalance',
                'severity' => 'critical',
                'title' => 'عدم توازن محاسبي',
                'reason' => 'Posted ledger totals are not balanced.',
                'count' => 1,
                'action' => 'Inspect journal entries before closing the period.',
            ];
        }

        $unusualDiscounts = FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'sales')
            ->whereIssued()
            ->where('subtotal', '>', 0)
            ->whereRaw('discount > (subtotal * 0.3)')
            ->count();
        if ($unusualDiscounts > 0) {
            $alerts[] = [
                'key' => 'unusual_discount',
                'severity' => 'medium',
                'title' => 'خصومات غير معتادة',
                'reason' => $unusualDiscounts.' issued sales invoices have discounts above 30%.',
                'count' => $unusualDiscounts,
                'action' => 'Review discount authorization.',
            ];
        }

        return $alerts;
    }

    private function ledgerImbalance(int $workspaceId): bool
    {
        $row = FinanceJournalEntryLine::withoutGlobalScopes()
            ->join('finance_journal_entries', 'finance_journal_entries.id', '=', 'finance_journal_entry_lines.journal_entry_id')
            ->where('finance_journal_entry_lines.workspace_id', $workspaceId)
            ->where('finance_journal_entries.status', 'posted')
            ->selectRaw('COALESCE(SUM(finance_journal_entry_lines.debit),0) as debit_total')
            ->selectRaw('COALESCE(SUM(finance_journal_entry_lines.credit),0) as credit_total')
            ->first();

        return Money::cmp($row?->debit_total ?? 0, $row?->credit_total ?? 0) !== 0;
    }
}
