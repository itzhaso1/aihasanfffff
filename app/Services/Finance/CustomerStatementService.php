<?php

namespace App\Services\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CustomerStatementService
{
    /**
     * @return array<string, mixed>
     */
    public function build(Workspace $workspace, Customer $customer, string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();

        $opening = $this->openingBalance($workspace->id, $customer->id, $fromDate);
        $lines = $this->periodLines($workspace->id, $customer->id, $fromDate, $toDate);

        $closing = $opening;
        foreach ($lines as &$line) {
            $closing = round($closing + (float) $line['debit'] - (float) $line['credit'], 2);
            $line['balance'] = $closing;
        }
        unset($line);

        return [
            'customer' => $customer,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'lines' => $lines,
            'invoices_total' => round(collect($lines)->where('kind', 'invoice')->sum('debit'), 2),
            'payments_total' => round(collect($lines)->where('kind', 'payment')->sum('credit'), 2),
            'credits_total' => round(collect($lines)->where('kind', 'credit_note')->sum('credit'), 2),
            'debits_total' => round(collect($lines)->where('kind', 'debit_note')->sum('debit'), 2),
        ];
    }

    private function openingBalance(int $workspaceId, int $customerId, Carbon $fromDate): float
    {
        $invoices = (float) FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('customer_id', $customerId)
            ->where('type', 'sales')
            ->whereDate('issue_date', '<', $fromDate->toDateString())
            ->where(function ($query): void {
                $query->where('invoice_status', 'issued')
                    ->orWhereIn('status', ['sent', 'unpaid', 'partial', 'paid', 'overdue']);
            })
            ->sum('total');

        $payments = $this->postedPaymentsQuery($workspaceId, $customerId)
            ->whereDate('finance_invoice_payments.payment_date', '<', $fromDate->toDateString())
            ->sum('finance_invoice_payments.amount');

        $credits = 0.0;
        $debits = 0.0;
        if (Schema::hasTable('finance_credit_notes')) {
            $credits = (float) FinanceCreditNote::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('customer_id', $customerId)
                ->where('type', 'credit')
                ->where('status', 'issued')
                ->whereDate('issue_date', '<', $fromDate->toDateString())
                ->sum('total');
            $debits = (float) FinanceCreditNote::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('customer_id', $customerId)
                ->where('type', 'debit')
                ->where('status', 'issued')
                ->whereDate('issue_date', '<', $fromDate->toDateString())
                ->sum('total');
        }

        return round($invoices + $debits - $payments - $credits, 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function periodLines(int $workspaceId, int $customerId, Carbon $fromDate, Carbon $toDate): array
    {
        $lines = collect();

        FinanceInvoice::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('customer_id', $customerId)
            ->where('type', 'sales')
            ->whereDate('issue_date', '>=', $fromDate->toDateString())
            ->whereDate('issue_date', '<=', $toDate->toDateString())
            ->where(function ($query): void {
                $query->where('invoice_status', 'issued')
                    ->orWhereIn('status', ['sent', 'unpaid', 'partial', 'paid', 'overdue']);
            })
            ->orderBy('issue_date')
            ->get()
            ->each(function (FinanceInvoice $invoice) use ($lines): void {
                $lines->push([
                    'date' => $invoice->issue_date?->toDateString(),
                    'kind' => 'invoice',
                    'reference' => $invoice->invoice_number,
                    'description' => 'فاتورة مبيعات',
                    'debit' => (float) $invoice->total,
                    'credit' => 0.0,
                    'invoice_id' => $invoice->id,
                ]);
            });

        $this->postedPaymentsQuery($workspaceId, $customerId)
            ->whereDate('finance_invoice_payments.payment_date', '>=', $fromDate->toDateString())
            ->whereDate('finance_invoice_payments.payment_date', '<=', $toDate->toDateString())
            ->orderBy('finance_invoice_payments.payment_date')
            ->get()
            ->each(function (FinanceInvoicePayment $payment) use ($lines): void {
                $lines->push([
                    'date' => $payment->payment_date?->toDateString(),
                    'kind' => 'payment',
                    'reference' => $payment->reference ?: ('PAY-'.$payment->id),
                    'description' => 'دفعة على الفاتورة '.($payment->invoice?->invoice_number ?? ''),
                    'debit' => 0.0,
                    'credit' => (float) $payment->amount,
                    'invoice_id' => $payment->invoice_id,
                ]);
            });

        if (Schema::hasTable('finance_credit_notes')) {
            FinanceCreditNote::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('customer_id', $customerId)
                ->where('status', 'issued')
                ->whereDate('issue_date', '>=', $fromDate->toDateString())
                ->whereDate('issue_date', '<=', $toDate->toDateString())
                ->orderBy('issue_date')
                ->get()
                ->each(function (FinanceCreditNote $note) use ($lines): void {
                    $isCredit = $note->isCredit();
                    $lines->push([
                        'date' => $note->issue_date?->toDateString(),
                        'kind' => $isCredit ? 'credit_note' : 'debit_note',
                        'reference' => $note->note_number,
                        'description' => ($isCredit ? 'إشعار دائن' : 'إشعار مدين').($note->reason ? ' — '.$note->reason : ''),
                        'debit' => $isCredit ? 0.0 : (float) $note->total,
                        'credit' => $isCredit ? (float) $note->total : 0.0,
                        'invoice_id' => $note->invoice_id,
                    ]);
                });
        }

        return $lines
            ->sortBy(fn (array $line): string => ($line['date'] ?? '').'-'.$line['kind'].'-'.$line['reference'])
            ->values()
            ->all();
    }

    private function postedPaymentsQuery(int $workspaceId, int $customerId)
    {
        $query = FinanceInvoicePayment::withoutGlobalScopes()
            ->select('finance_invoice_payments.*')
            ->join('finance_invoices', 'finance_invoices.id', '=', 'finance_invoice_payments.invoice_id')
            ->where('finance_invoice_payments.workspace_id', $workspaceId)
            ->where('finance_invoices.customer_id', $customerId)
            ->where('finance_invoices.type', 'sales')
            ->with('invoice');

        if (Schema::hasColumn('finance_invoice_payments', 'status')) {
            $query->where(function ($builder): void {
                $builder->whereNull('finance_invoice_payments.status')
                    ->orWhere('finance_invoice_payments.status', 'posted');
            });
        }

        return $query;
    }
}
