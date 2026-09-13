<?php

namespace App\EInvoicing\Xml;

use App\Enums\EInvoicing\ElectronicDocumentKind;

/**
 * Locally generated UBL XML. Contains no signature, hash, PIH, ICV, or QR.
 */
final readonly class GeneratedEInvoiceXml
{
    /**
     * @param  list<string>  $validationErrors
     */
    public function __construct(
        public string $xml,
        public ElectronicDocumentKind $documentKind,
        public string $rootLocalName,
        public string $rootNamespace,
        public int $workspaceId,
        public int $sourceSnapshotId,
        public string $sourceType,
        public int $sourceId,
        public string $documentNumber,
        public string $documentUuid,
        public bool $schemaValid,
        public array $validationErrors = [],
    ) {}
}
