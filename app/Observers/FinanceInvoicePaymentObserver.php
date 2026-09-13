<?php

namespace App\Observers;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoicePayment;
use App\Services\Finance\InvoiceStateService;
use App\Services\Finance\InvoiceService;
use Illuminate\Support\Facades\Schema;

class FinanceInvoicePaymentObserver
{
    public function saved(FinanceInvoicePayment $payment): void
    {
        $this->syncInvoice($payment);
    }

    public function deleted(FinanceInvoicePayment $payment): void
    {
        $this->syncInvoice($payment);
    }

    private function syncInvoice(FinanceInvoicePayment $payment): void
    {
        if (! Schema::hasColumn('finance_invoices', 'invoice_status')) {
            return;
        }

        $invoice = FinanceInvoice::withoutGlobalScopes()->find($payment->invoice_id);
        if (! $invoice) {
            return;
        }

        $invoiceStatus = $invoice->invoice_status
            ?? app(InvoiceStateService::class)->resolveInvoiceStatus($invoice->status);
        if ($invoiceStatus !== 'issued') {
            return;
        }

        app(InvoiceService::class)->syncPaymentStatus($invoice);
    }
}
