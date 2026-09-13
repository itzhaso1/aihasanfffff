<?php

namespace App\EInvoicing\Security;

/**
 * Future secret/key-management boundary.
 *
 * Implementations must not persist private keys in normal application tables.
 * Phase 9 does not provide a production implementation.
 */
interface PrivateKeyMaterial
{
    public function label(): string;
}
