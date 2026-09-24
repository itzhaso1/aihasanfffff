<?php

namespace App\EInvoicing\Security\Exceptions;

use App\EInvoicing\Security\Exceptions\EInvoiceSecurityException;

final class PrivateKeyStoreException extends EInvoiceSecurityException
{
    public function __construct(
        string $message,
        public readonly ?int $referenceId = null,
        ?string $operation = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, operation: $operation, reason: $reason);
    }
}
