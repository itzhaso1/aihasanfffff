<?php

namespace App\Services\Finance;

use App\Models\Customer;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceQuote;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceExportService
{
    public function __construct(
        private readonly CustomerBalanceService $customerBalanceService,
        private readonly CustomerStatementService $customerStatementService,
    ) {}

    public function invoices(Workspace $workspace, ?string $type = null): StreamedResponse
    {
        $type = in_array($type, ['sales', 'purchase'], true) ? $type : null;

        return $this->stream('invoices-'.$workspace->id.'.csv', [
            'invoice_number',
            'type',
            'document_status',
            'payment_status',
            'customer',
            'issue_date',
            'due_date',
            'currency',
            'total',
            'amount_paid',
            'amount_due',
        ], function ($out) use ($workspace, $type): void {
            FinanceInvoice::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->when($type, fn ($query) => $query->where('type', $type))
                ->with('customer')
                ->orderBy('id')
                ->chunkById(200, function ($invoices) use ($out): void {
                    foreach ($invoices as $invoice) {
                        fputcsv($out, [
                            $invoice->invoice_number,
                            $invoice->type,
                            $invoice->resolvedInvoiceStatus(),
                            $invoice->payment_status,
                            $invoice->customer?->name ?: $invoice->customer_name,
                            $invoice->issue_date?->toDateString(),
                            $invoice->due_date?->toDateString(),
                            $invoice->currency,
                            number_format((float) $invoice->total, 2, '.', ''),
                            number_format((float) $invoice->amount_paid, 2, '.', ''),
                            number_format((float) $invoice->amount_due, 2, '.', ''),
                        ]);
                    }
                });
        });
    }

    public function payments(Workspace $workspace): StreamedResponse
    {
        return $this->stream('payments-'.$workspace->id.'.csv', [
            'id',
            'invoice_number',
            'payment_date',
            'method',
            'reference',
            'amount',
            'status',
            'currency',
        ], function ($out) use ($workspace): void {
            FinanceInvoicePayment::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->with('invoice')
                ->orderBy('id')
                ->chunkById(200, function ($payments) use ($out): void {
                    foreach ($payments as $payment) {
                        fputcsv($out, [
                            $payment->id,
                            $payment->invoice?->invoice_number,
                            $payment->payment_date?->toDateString(),
                            $payment->method,
                            $payment->reference,
                            number_format((float) $payment->amount, 2, '.', ''),
                            $payment->status ?: FinanceInvoicePayment::STATUS_POSTED,
                            $payment->invoice?->currency ?: 'SAR',
                        ]);
                    }
                });
        });
    }

    public function customers(Workspace $workspace): StreamedResponse
    {
        return $this->stream('customers-balances-'.$workspace->id.'.csv', [
            'customer_id',
            'name',
            'email',
            'phone',
            'outstanding_balance',
        ], function ($out) use ($workspace): void {
            Customer::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->orderBy('id')
                ->chunkById(200, function ($customers) use ($out, $workspace): void {
                    $ids = $customers->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $balances = $this->customerBalanceService->outstandingByCustomerIds((int) $workspace->id, $ids);
                    foreach ($customers as $customer) {
                        fputcsv($out, [
                            $customer->id,
                            $customer->name,
                            $customer->email,
                            $customer->phone,
                            number_format((float) ($balances[(int) $customer->id] ?? 0), 2, '.', ''),
                        ]);
                    }
                });
        });
    }

    public function expenses(Workspace $workspace): StreamedResponse
    {
        return $this->stream('expenses-'.$workspace->id.'.csv', [
            'expense_number',
            'expense_date',
            'supplier',
            'category',
            'amount',
            'tax_amount',
            'total',
            'currency',
            'status',
            'payment_method',
        ], function ($out) use ($workspace): void {
            FinanceExpense::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->with(['supplier', 'category'])
                ->orderBy('id')
                ->chunkById(200, function ($expenses) use ($out): void {
                    foreach ($expenses as $expense) {
                        fputcsv($out, [
                            $expense->expense_number,
                            $expense->expense_date?->toDateString(),
                            $expense->supplier?->name,
                            $expense->category?->name,
                            number_format((float) $expense->amount, 2, '.', ''),
                            number_format((float) $expense->tax_amount, 2, '.', ''),
                            number_format((float) $expense->total, 2, '.', ''),
                            $expense->currency,
                            $expense->status,
                            $expense->payment_method,
                        ]);
                    }
                });
        });
    }

    public function quotes(Workspace $workspace): StreamedResponse
    {
        $headers = [
            'quote_number',
            'status',
            'outcome',
            'customer',
            'issue_date',
            'expiry_date',
            'currency',
            'total',
        ];

        return $this->stream('quotes-'.$workspace->id.'.csv', $headers, function ($out) use ($workspace): void {
            FinanceQuote::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->with('customer')
                ->orderBy('id')
                ->chunkById(200, function ($quotes) use ($out): void {
                    foreach ($quotes as $quote) {
                        fputcsv($out, [
                            $quote->quote_number,
                            $quote->status,
                            Schema::hasColumn('finance_quotes', 'outcome') ? $quote->outcome : '',
                            $quote->customer?->name,
                            $quote->issue_date?->toDateString(),
                            $quote->expiry_date?->toDateString(),
                            $quote->currency,
                            number_format((float) $quote->total, 2, '.', ''),
                        ]);
                    }
                });
        });
    }

    public function statement(Workspace $workspace, Customer $customer, string $from, string $to): StreamedResponse
    {
        $statement = $this->customerStatementService->build($workspace, $customer, $from, $to);

        return $this->stream(
            'statement-'.$customer->id.'-'.$from.'.csv',
            ['date', 'kind', 'reference', 'description', 'debit', 'credit', 'balance'],
            function ($out) use ($statement): void {
                fputcsv($out, [
                    $statement['from'],
                    'opening',
                    '',
                    'الرصيد الافتتاحي',
                    '',
                    '',
                    number_format((float) $statement['opening_balance'], 2, '.', ''),
                ]);
                foreach ($statement['lines'] as $line) {
                    fputcsv($out, [
                        $line['date'] ?? '',
                        $line['kind'] ?? '',
                        $line['reference'] ?? '',
                        $line['description'] ?? '',
                        number_format((float) ($line['debit'] ?? 0), 2, '.', ''),
                        number_format((float) ($line['credit'] ?? 0), 2, '.', ''),
                        number_format((float) ($line['balance'] ?? 0), 2, '.', ''),
                    ]);
                }
                fputcsv($out, [
                    $statement['to'],
                    'closing',
                    '',
                    'الرصيد الختامي',
                    '',
                    '',
                    number_format((float) $statement['closing_balance'], 2, '.', ''),
                ]);
            }
        );
    }

    /**
     * @param  array<int, string>  $headers
     * @param  callable(resource):void  $writer
     */
    public function stream(string $filename, array $headers, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $writer): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            $writer($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
