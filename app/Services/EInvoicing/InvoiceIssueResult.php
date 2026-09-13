<?php

namespace App\Services\EInvoicing;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\QR\QrPayload;
use App\EInvoicing\Xml\GeneratedEInvoiceXml;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\Finance\IssuedDocumentSnapshot;

/**
 * Outcome of connecting an issued snapshot to the electronic-invoice domain.
 *
 * XML and QR are derived artifacts. They are returned for the current call
 * and are not a second stored source of truth.
 */
final readonly class InvoiceIssueResult
{
    public function __construct(
        public IssuedDocumentSnapshot $snapshot,
        public EInvoiceDocumentRecord $record,
        public EInvoiceDocument $document,
        public bool $reusedDocument,
        public bool $xmlAvailable,
        public bool $securityGenerated,
        public bool $qrAvailable,
        public ?GeneratedEInvoiceXml $xml = null,
        public ?QrPayload $qr = null,
        public ?string $xmlSkipReason = null,
        public ?string $securitySkipReason = null,
        public ?string $qrSkipReason = null,
    ) {}
}
