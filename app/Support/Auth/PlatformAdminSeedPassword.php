<?php

namespace App\Support\Auth;

use App\Exceptions\PlatformAdminSeedPasswordException;

final class PlatformAdminSeedPassword
{
    public const MIN_LENGTH = 12;

    /**
     * Known defaults and trivial values that must never seed a platform admin.
     *
     * @var list<string>
     */
    public const REJECTED = [
        'password',
        'admin',
        'secret',
        'changeme',
        'qwerty',
        '123456',
        '12345678',
        '123456789',
        'admin123',
        'hasem',
        'platform',
        'platformadmin',
    ];

    /**
     * Return a seedable password, or fail without echoing the value.
     */
    public static function require(?string $password): string
    {
        if ($password === null) {
            throw PlatformAdminSeedPasswordException::missing();
        }

        $trimmed = trim($password);

        if ($trimmed === '') {
            throw PlatformAdminSeedPasswordException::empty();
        }

        if (mb_strlen($trimmed) < self::MIN_LENGTH) {
            throw PlatformAdminSeedPasswordException::rejected();
        }

        if (in_array(mb_strtolower($trimmed), self::REJECTED, true)) {
            throw PlatformAdminSeedPasswordException::rejected();
        }

        return $trimmed;
    }

    /**
     * Read PLATFORM_ADMIN_PASSWORD from the process environment (not cached env()).
     */
    public static function fromEnvironment(): string
    {
        if (! self::isPresentInEnvironment()) {
            throw PlatformAdminSeedPasswordException::missing();
        }

        $raw = $_ENV['PLATFORM_ADMIN_PASSWORD']
            ?? $_SERVER['PLATFORM_ADMIN_PASSWORD']
            ?? getenv('PLATFORM_ADMIN_PASSWORD');

        if ($raw === false || $raw === null) {
            throw PlatformAdminSeedPasswordException::missing();
        }

        return self::require((string) $raw);
    }

    private static function isPresentInEnvironment(): bool
    {
        return array_key_exists('PLATFORM_ADMIN_PASSWORD', $_ENV)
            || array_key_exists('PLATFORM_ADMIN_PASSWORD', $_SERVER)
            || getenv('PLATFORM_ADMIN_PASSWORD') !== false;
    }
}
