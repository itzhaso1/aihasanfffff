<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use Illuminate\Support\Facades\Schema;

class BillingDashboardService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function metrics(array $filters = []): array
    {
        $base = $this->filteredInvoices($filters);
        $issued = (clone $base)->where(function ($query): void {
            $query->where('invoice_status', 'issued')
                ->orWhere(function ($legacy): void {
                    $legacy->whereNull('invoice_status')
                        ->whereIn('status', ['sent', 'unpaid', 'partial', 'paid', 'overdue']);
                });
        });

        $today = now()->startOfDay();
        $dueToday = (clone $issued)
            ->whereDate('due_date', $today->toDateString())
            ->whereIn('payment_status', ['unpaid', 'partial', 'overdue'])
            ->count();
        $upcoming = (clone $issued)
            ->whereDate('due_date', '>', $today->toDateString())
            ->whereDate('due_date', '<=', $today->copy()->addDays(7)->toDateString())
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->count();

        $salesIssued = (clone $issued)->where('type', 'sales');

        return [
            'total_invoices' => (clone $base)->count(),
            'draft' => (clone $base)->whereInvoiceStatus('draft')->count(),
            'issued' => (clone $issued)->count(),
            'paid' => (clone $issued)->wherePaymentStatus('paid')->count(),
            'partial' => (clone $issued)->wherePaymentStatus('partial')->count(),
            'overdue' => (clone $issued)->wherePaymentStatus('overdue')->count(),
            'cancelled' => (clone $base)->whereInvoiceStatus('cancelled')->count(),
            'unpaid' => (clone $issued)->wherePaymentStatus('unpaid')->count(),
            'upcoming_due' => $upcoming,
            'due_today' => $dueToday,
            'total_revenue' => round((float) (clone $salesIssued)->sum('total'), 2),
            'outstanding_amount' => round((float) (clone $salesIssued)->sum('amount_due'), 2),
            'overdue_amount' => round((float) (clone $salesIssued)->wherePaymentStatus('overdue')->sum('amount_due'), 2),
            'payments_received' => round($this->paymentsReceived($filters), 2),
            'credits_issued' => round($this->creditsIssued($filters), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredInvoices(array $filters)
    {
        return FinanceInvoice::query()
            ->when(($filters['type'] ?? '') !== '', fn ($query) => $query->where('type', $filters['type']))
            ->when(($filters['customer_id'] ?? '') !== '', fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(($filters['currency'] ?? '') !== '', fn ($query) => $query->where('currency', $filters['currency']))
            ->when(($filters['from'] ?? '') !== '', fn ($query) => $query->whereDate('issue_date', '>=', $filters['from']))
            ->when(($filters['to'] ?? '') !== '', fn ($query) => $query->whereDate('issue_date', '<=', $filters['to']));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paymentsReceived(array $filters): float
    {
        $query = FinanceInvoicePayment::query()
            ->whereHas('invoice', function ($invoiceQuery) use ($filters): void {
                $invoiceQuery->where('type', 'sales');
                if (($filters['customer_id'] ?? '') !== '') {
                    $invoiceQuery->where('customer_id', $filters['customer_id']);
                }
                if (($filters['currency'] ?? '') !== '') {
                    $invoiceQuery->where('currency', $filters['currency']);
                }
            });

        if (Schema::hasColumn('finance_invoice_payments', 'status')) {
            $query->where(function ($builder): void {
                $builder->whereNull('status')->orWhere('status', 'posted');
            });
        }

        if (($filters['from'] ?? '') !== '') {
            $query->whereDate('payment_date', '>=', $filters['from']);
        }
        if (($filters['to'] ?? '') !== '') {
            $query->whereDate('payment_date', '<=', $filters['to']);
        }

        return (float) $query->sum('amount');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function creditsIssued(array $filters): float
    {
        if (! Schema::hasTable('finance_credit_notes')) {
            return 0;
        }

        $query = FinanceCreditNote::query()
            ->where('type', 'credit')
            ->where('status', 'issued')
            ->when(($filters['customer_id'] ?? '') !== '', fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(($filters['from'] ?? '') !== '', fn ($q) => $q->whereDate('issue_date', '>=', $filters['from']))
            ->when(($filters['to'] ?? '') !== '', fn ($q) => $q->whereDate('issue_date', '<=', $filters['to']));

        return (float) $query->sum('total');
    }
}
