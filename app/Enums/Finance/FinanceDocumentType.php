<?php

namespace App\Enums\Finance;

/**
 * Finance documents that can share finance_document_deliveries.
 * Quote, invoice, reminder, and receipt email sends persist here.
 */
enum FinanceDocumentType: string
{
    case Quote = 'quote';
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case InvoiceReminder = 'invoice_reminder';
}
