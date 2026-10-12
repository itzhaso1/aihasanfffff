<?php

namespace App\Support\Feature;

/**
 * Business-only plan keys that remain in the shared platform matrix.
 * Chat surfaces must not advertise or gate Catalog, Commerce, Inbox, or Workers on them.
 */
final class ChatBusinessFeatureKeys
{
    /**
     * @var list<string>
     */
    public const KEYS = ['finance', 'pos', 'inventory'];

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * @param  list<mixed>  $features
     * @return list<string>
     */
    public static function except(array $features): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $feature): string => is_string($feature) ? $feature : '',
                $features,
            ),
            static fn (string $feature): bool => $feature !== '' && ! self::contains($feature),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function exceptComparisonRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! self::contains((string) ($row['key'] ?? '')),
        ));
    }
}
