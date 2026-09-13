<?php

namespace App\Enums\EInvoicing;

enum ElectronicDocumentKind: string
{
    case TaxInvoice = 'tax_invoice';
    case SimplifiedTaxInvoice = 'simplified_tax_invoice';
    case CreditNote = 'credit_note';
    case SimplifiedCreditNote = 'simplified_credit_note';
    case DebitNote = 'debit_note';
    case SimplifiedDebitNote = 'simplified_debit_note';
    case PosCashierInvoice = 'pos_cashier_invoice';
    case PurchaseInvoice = 'purchase_invoice';

    public function isCredit(): bool
    {
        return in_array($this, [self::CreditNote, self::SimplifiedCreditNote], true);
    }

    public function isDebit(): bool
    {
        return in_array($this, [self::DebitNote, self::SimplifiedDebitNote], true);
    }

    public function isSalesInvoice(): bool
    {
        return in_array($this, [self::TaxInvoice, self::SimplifiedTaxInvoice], true);
    }
}
