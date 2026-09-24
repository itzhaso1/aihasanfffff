<?php

namespace App\EInvoicing\Security\Exceptions;

final class EgsOnboardingException extends EInvoiceSecurityException
{
    public function __construct(
        string $message,
        public readonly ?int $onboardingId = null,
        ?string $operation = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, operation: $operation, reason: $reason);
    }
}
