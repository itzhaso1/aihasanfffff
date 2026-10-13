<?php

declare(strict_types=1);

namespace App\AI\Core\Contracts;

/**
 * Safe provider-failure details. Implementations must not expose API keys,
 * exception messages, or customer text through these methods.
 */
interface StructuredProviderFailure
{
    public function providerErrorCode(): string;

    public function httpStatus(): ?int;
}
