<?php

namespace App\Enums\EInvoicing;

/**
 * Central catalog of electronic invoice type codes from the architecture design.
 * These are domain labels only. They are not XML, UBL, or ZATCA submission values.
 */
enum InvoiceTypeCode: string
{
    case TaxInvoice = '388';
    case CreditNote = '381';
    case DebitNote = '383';
}
