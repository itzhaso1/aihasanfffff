<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\Exceptions\ProductionCryptographicProfileException;

/**
 * Bound in the production container. Not a ZATCA implementation.
 * Phase 9A left production-critical choices unresolved; this type fails loudly.
 */
final class UnresolvedProductionZatcaCryptographicProfile implements CryptographicProfile
{
    public const IDENTIFIER = 'production.zatca.unresolved';

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isTestOnly(): bool
    {
        return false;
    }

    public function isProductionZatca(): bool
    {
        return true;
    }

    public function activate(): never
    {
        throw new ProductionCryptographicProfileException(
            'Production ZATCA cryptographic profile is unresolved and unavailable.',
            operation: 'production_profile',
            reason: 'production_profile_unresolved',
        );
    }
}
