<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceXmlDto
{
    public function __construct(
        public string $documentNumber,
        public string $documentUuid,
        public string $documentKind,
        public string $xml,
        public string $rootLocalName,
        public bool $schemaValid,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'document_number' => $this->documentNumber,
            'document_uuid' => $this->documentUuid,
            'document_kind' => $this->documentKind,
            'root_local_name' => $this->rootLocalName,
            'schema_valid' => $this->schemaValid,
            'xml' => $this->xml,
        ];
    }
}
