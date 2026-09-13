<?php

namespace App\EInvoicing\Security\Exceptions;

use RuntimeException;

class EInvoiceSecurityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $documentIdentity = null,
        public readonly ?string $sequenceIdentity = null,
        public readonly ?string $operation = null,
        public readonly ?string $reason = null,
    ) {
        $parts = array_filter([
            $documentIdentity !== null ? "document={$documentIdentity}" : null,
            $sequenceIdentity !== null ? "sequence={$sequenceIdentity}" : null,
            $operation !== null ? "operation={$operation}" : null,
            $reason !== null ? "reason={$reason}" : null,
            $message,
        ]);

        parent::__construct(implode(' | ', $parts));
    }
}
