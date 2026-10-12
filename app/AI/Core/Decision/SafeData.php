<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

/**
 * Makes trace data safe to serialize: redacts credential-like keys and values, drops
 * non-scalar values, and bounds depth, item count and string length.
 */
final class SafeData
{
    public const REDACTED = '[REDACTED]';

    private const MAX_DEPTH = 4;

    private const MAX_ITEMS = 50;

    private const MAX_STRING_LENGTH = 500;

    private const SENSITIVE_KEY = '/(secret|token|password|passwd|api[_-]?key|authorization|credential|cookie|signature)/i';

    private const SENSITIVE_VALUES = [
        '/sk-[A-Za-z0-9_\-]{10,}/',
        '/Bearer\s+\S+/i',
        '/whsec_\S{8,}/',
        '/AIza[0-9A-Za-z_\-]{20,}/',
    ];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $data): array
    {
        return self::sanitizeLevel($data, 0);
    }

    public static function text(string $value): string
    {
        $redacted = preg_replace(self::SENSITIVE_VALUES, self::REDACTED, $value) ?? self::REDACTED;

        return mb_substr($redacted, 0, self::MAX_STRING_LENGTH);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sanitizeLevel(array $data, int $depth): array
    {
        $clean = [];
        foreach (array_slice($data, 0, self::MAX_ITEMS, true) as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $clean[$key] = self::REDACTED;

                continue;
            }
            $clean[$key] = self::value($value, $depth);
        }

        return $clean;
    }

    private static function value(mixed $value, int $depth): mixed
    {
        return match (true) {
            is_string($value) => self::text($value),
            is_float($value) => is_finite($value) ? $value : null,
            is_int($value), is_bool($value), $value === null => $value,
            is_array($value) && $depth < self::MAX_DEPTH => self::sanitizeLevel($value, $depth + 1),
            default => null,
        };
    }
}
