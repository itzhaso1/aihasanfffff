<?php

namespace App\Support\Subscription;

/**
 * Server-side subscription billing environment rules.
 * Local/no-charge activation is never allowed in production or staging.
 */
final class SubscriptionBillingGuard
{
    /**
     * @var list<string>
     */
    public const LOCAL_PROVIDER_ENVIRONMENTS = ['local', 'testing'];

    /**
     * @var list<string>
     */
    public const TRUSTED_VERIFICATION_SOURCES = [
        'provider_webhook',
        'local_billing_provider',
    ];

    public static function localProviderAllowed(?string $environment = null): bool
    {
        $environment ??= app()->environment();

        return in_array($environment, self::LOCAL_PROVIDER_ENVIRONMENTS, true);
    }

    public static function billingProviderClass(?string $environment = null): string
    {
        return self::localProviderAllowed($environment)
            ? \App\Services\Subscription\LocalSubscriptionBillingProvider::class
            : \App\Services\Subscription\UnconfiguredSubscriptionBillingProvider::class;
    }

    public static function isTrustedVerificationSource(string $source, ?string $environment = null): bool
    {
        if ($source === 'provider_webhook') {
            return true;
        }

        if ($source === 'local_billing_provider') {
            return self::localProviderAllowed($environment);
        }

        return false;
    }
}
