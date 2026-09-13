<?php

namespace App\EInvoicing\QR\Harness;

use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\InvoiceHash;

/**
 * TEST ONLY. Maps Phase 7 invoice_hash onto Tag 6 representation candidates.
 * Does not re-hash XML. Does not change Phase 8 default encoding.
 */
final class TestQrTag6Encoder
{
    public function field(InvoiceHash $invoiceHash, TestQrTag6Representation $representation): QrField
    {
        return match ($representation) {
            TestQrTag6Representation::RawSha256 => QrField::binary(QrTag::INVOICE_HASH, $invoiceHash->binary()),
            TestQrTag6Representation::Base64Text => QrField::of(QrTag::INVOICE_HASH, $invoiceHash->value()),
        };
    }
}
