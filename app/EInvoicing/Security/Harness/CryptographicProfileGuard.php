<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\CryptographicStampSigner;
use App\EInvoicing\Security\Exceptions\ProductionCryptographicProfileException;
use App\Services\EInvoicing\Security\TestCryptographicStampSigner;
use Illuminate\Contracts\Foundation\Application;

/**
 * Prevents test cryptographic profiles from being treated as production ZATCA.
 * Does not silently fall back.
 */
final class CryptographicProfileGuard
{
    public function __construct(
        private readonly ?Application $app = null,
        private readonly ?bool $treatAsProduction = null,
    ) {}

    public function assertProfileUsable(CryptographicProfile $profile): void
    {
        if ($profile->isProductionZatca()) {
            throw new ProductionCryptographicProfileException(
                'Production ZATCA cryptographic profile is unresolved and unavailable.',
                operation: 'production_guard',
                reason: 'production_profile_unresolved',
            );
        }

        if ($profile->isTestOnly() && $this->isProductionEnvironment()) {
            throw new ProductionCryptographicProfileException(
                'Production ZATCA cryptographic profile is unresolved and unavailable.',
                operation: 'production_guard',
                reason: 'test_profile_in_production',
            );
        }
    }

    public function assertSignerMayRun(CryptographicStampSigner $signer): void
    {
        if ($signer->isProductionIdentity()) {
            throw new ProductionCryptographicProfileException(
                'Production ZATCA cryptographic profile is unresolved and unavailable.',
                operation: 'production_guard',
                reason: 'production_identity_forbidden',
            );
        }

        if ($signer->isTestOnly() && $this->isProductionEnvironment()) {
            throw new ProductionCryptographicProfileException(
                'Production ZATCA cryptographic profile is unresolved and unavailable.',
                operation: 'production_guard',
                reason: 'test_signer_in_production',
            );
        }

        if ($signer instanceof TestCryptographicStampSigner && $this->isProductionEnvironment()) {
            throw new ProductionCryptographicProfileException(
                'Production ZATCA cryptographic profile is unresolved and unavailable.',
                operation: 'production_guard',
                reason: 'test_signer_in_production',
            );
        }
    }

    private function isProductionEnvironment(): bool
    {
        if ($this->treatAsProduction !== null) {
            return $this->treatAsProduction;
        }

        if ($this->app !== null) {
            return $this->app->environment('production');
        }

        if (function_exists('app')) {
            try {
                return app()->environment('production');
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
