<?php

namespace App\Services\Finance\Assistant\Tools;

final class CustomersInvoicesTool extends InvoicesByCustomerTool
{
    public function name(): string
    {
        return 'customers.invoices';
    }

    public function description(): string
    {
        return 'Alias for invoices.by_customer — list a customer sales invoices.';
    }
}
