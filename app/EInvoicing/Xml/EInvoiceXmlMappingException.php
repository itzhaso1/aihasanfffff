<?php

namespace App\EInvoicing\Xml;

use RuntimeException;

class EInvoiceXmlMappingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $sourceIdentity = null,
        public readonly ?string $fieldPath = null,
    ) {
        $parts = array_filter([
            $sourceIdentity !== null ? "source={$sourceIdentity}" : null,
            $fieldPath !== null ? "field={$fieldPath}" : null,
            $message,
        ]);

        parent::__construct(implode(' | ', $parts));
    }
}
