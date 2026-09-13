<?php

namespace App\EInvoicing\QR;

use RuntimeException;

class QrEncodingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $documentIdentity = null,
        public readonly ?string $field = null,
        public readonly ?string $reason = null,
    ) {
        $parts = array_filter([
            $documentIdentity !== null ? "document={$documentIdentity}" : null,
            $field !== null ? "field={$field}" : null,
            $reason !== null ? "reason={$reason}" : null,
            $message,
        ]);

        parent::__construct(implode(' | ', $parts));
    }
}
