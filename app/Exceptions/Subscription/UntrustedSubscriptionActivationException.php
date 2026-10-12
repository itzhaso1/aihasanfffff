<?php

namespace App\Exceptions\Subscription;

use RuntimeException;

class UntrustedSubscriptionActivationException extends RuntimeException
{
    public static function frontendConfirmation(): self
    {
        return new self('Subscription activation requires trusted payment verification.');
    }

    public static function untrustedSource(string $source): self
    {
        return new self('Subscription activation rejected: verification source is not trusted.');
    }

    public static function localProviderBlocked(): self
    {
        return new self('Local subscription billing is not available in this environment.');
    }

    public static function providerUnconfigured(): self
    {
        return new self('Subscription billing provider is not configured.');
    }
}
