<?php

namespace App\Support\Chat;

/**
 * Turns a catalog availability payload into the token TruthCheck trusts.
 */
final class CatalogAvailabilityFact
{
    public static function token(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'in_stock' : 'out_of_stock';
        }

        if (is_string($value)) {
            return self::fromLabel($value);
        }

        if (! is_array($value)) {
            return null;
        }

        if (array_key_exists('available', $value) && is_bool($value['available'])) {
            return $value['available'] ? 'in_stock' : 'out_of_stock';
        }

        $label = $value['label'] ?? null;

        return is_string($label) ? self::fromLabel($label) : null;
    }

    private static function fromLabel(string $label): ?string
    {
        $label = strtolower(trim($label));

        return match ($label) {
            'in_stock', 'available' => 'in_stock',
            'out_of_stock', 'unavailable' => 'out_of_stock',
            default => null,
        };
    }
}
