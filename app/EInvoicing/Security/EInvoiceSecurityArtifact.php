<?php

namespace App\EInvoicing\Security;

/**
 * Durable local security artifacts for one electronic document.
 */
final readonly class EInvoiceSecurityArtifact
{
    public function __construct(
        public int $workspaceId,
        public int $egsUnitId,
        public int $eInvoiceDocumentId,
        public Icv $icv,
        public Pih $pih,
        public InvoiceHash $invoiceHash,
        public CanonicalXml $canonicalXml,
        public string $enrichedXml,
        public string $hashAlgorithm,
        public string $canonicalizationMethod,
        public string $sourceXmlDigest,
        public bool $reused,
    ) {}
}
