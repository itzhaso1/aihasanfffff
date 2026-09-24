<?php

namespace App\EInvoicing\Security;

/**
 * Legacy marker for future key-management boundaries.
 *
 * @see PrivateKeyStore Production and test signing must use {@see PrivateKeyStore} with a
 *      {@see \App\Models\EInvoicing\PrivateKeyReference} row — never PEM in DB.
 */
interface PrivateKeyMaterial
{
    public function label(): string;
}
