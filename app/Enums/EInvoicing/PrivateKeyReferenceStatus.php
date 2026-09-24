<?php

namespace App\Enums\EInvoicing;

enum PrivateKeyReferenceStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Destroyed = 'destroyed';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
