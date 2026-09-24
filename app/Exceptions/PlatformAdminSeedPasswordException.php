<?php

namespace App\Exceptions;

use RuntimeException;

class PlatformAdminSeedPasswordException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('PLATFORM_ADMIN_PASSWORD is missing. Set a strong password in the environment before db:seed.');
    }

    public static function empty(): self
    {
        return new self('PLATFORM_ADMIN_PASSWORD is empty. Set a strong password in the environment before db:seed.');
    }

    public static function rejected(): self
    {
        return new self('PLATFORM_ADMIN_PASSWORD is too weak or matches a known default. Set a strong password in the environment before db:seed.');
    }
}
