<?php

namespace App\Support\Email;

final class CustomerMailboxEncryption
{
    public const SSL = 'ssl';

    public const TLS = 'tls';

    public const NONE = 'none';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::SSL, self::TLS, self::NONE];
    }

    public static function normalize(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, self::values(), true) ? $normalized : null;
    }

    public static function resolveSmtp(?string $stored, int $port): string
    {
        $normalized = self::normalize($stored);
        if ($normalized !== null) {
            return $normalized;
        }

        return match ($port) {
            465 => self::SSL,
            587 => self::TLS,
            default => self::NONE,
        };
    }

    public static function resolveImap(?string $stored, int $port): string
    {
        $normalized = self::normalize($stored);
        if ($normalized !== null) {
            return $normalized;
        }

        return match ($port) {
            993 => self::SSL,
            143 => self::TLS,
            default => self::NONE,
        };
    }
}
