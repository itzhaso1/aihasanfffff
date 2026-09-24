<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\PrivateKeyStoreException;
use Illuminate\Contracts\Foundation\Application;

/**
 * Fail-closed guard for non-production private-key store drivers.
 */
final class PrivateKeyStoreEnvironmentGuard
{
    public function __construct(
        private readonly ?Application $app = null,
        private readonly ?bool $treatAsProduction = null,
    ) {}

    public function assertDevDriverAllowed(string $driverLabel): void
    {
        if ($this->isProductionEnvironment()) {
            throw new PrivateKeyStoreException(
                'Private key store driver is not available in production.',
                operation: 'private_key_store',
                reason: 'dev_driver_forbidden_in_production',
            );
        }
    }

    public function isProductionEnvironment(): bool
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
