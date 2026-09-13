<?php

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Integer-minor-unit money helper. All arithmetic happens in cents (2 decimal places).
 *
 * Do not store FX rates or tax percentages as Money values. percentOf() and
 * extractInclusiveTax() only apply a percentage to an already-rounded amount.
 */
final class Money
{
    public const SCALE = 2;

    public static function minor(int|float|string $value): int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            $value = number_format($value, self::SCALE, '.', '');
        }

        $normalized = self::normalizeString((string) $value);
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');

        if ($normalized === '' || ! preg_match('/^\d+(\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');
        $fraction = substr(str_pad($fraction, self::SCALE, '0'), 0, self::SCALE);
        $minor = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    public static function fromMinor(int $minor): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $formatted = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), self::SCALE, '0', STR_PAD_LEFT);

        return $negative ? '-'.$formatted : $formatted;
    }

    public static function of(int|float|string $value): string
    {
        return self::fromMinor(self::minor($value));
    }

    public static function round(int|float|string $value): float
    {
        return (float) self::of($value);
    }

    public static function add(int|float|string ...$values): string
    {
        $sum = 0;
        foreach ($values as $value) {
            $sum += self::minor($value);
        }

        return self::fromMinor($sum);
    }

    public static function sub(int|float|string $left, int|float|string $right): string
    {
        return self::fromMinor(self::minor($left) - self::minor($right));
    }

    public static function mul(int|float|string $left, int|float|string $right): string
    {
        return self::fromMinor(intdiv(self::minor($left) * self::minor($right), 100));
    }

    /**
     * Quantity (up to 3 decimal places) × unit price (2 decimal places).
     */
    public static function quantityTimesUnitPrice(int|float|string $quantity, int|float|string $unitPrice): string
    {
        $qtyMilli = (int) round(((float) $quantity) * 1000, 0, PHP_ROUND_HALF_UP);
        $priceCents = self::minor($unitPrice);
        $cents = (int) round(($qtyMilli * $priceCents) / 1000, 0, PHP_ROUND_HALF_UP);

        return self::fromMinor($cents);
    }

    /**
     * tax = round(amount × percent / 100) in integer cents (half-up).
     */
    public static function percentOf(int|float|string $amount, float $percent): string
    {
        if ($percent < 0) {
            throw new InvalidArgumentException('النسبة المئوية لا يمكن أن تكون سالبة.');
        }

        $cents = (int) round(self::minor($amount) * $percent / 100, 0, PHP_ROUND_HALF_UP);

        return self::fromMinor($cents);
    }

    /**
     * Inclusive VAT: tax = round(inclusive × rate / (100 + rate)) in integer cents.
     */
    public static function extractInclusiveTax(int|float|string $inclusiveAmount, float $rate): string
    {
        if ($rate <= 0) {
            return self::fromMinor(0);
        }

        $cents = (int) round(self::minor($inclusiveAmount) * $rate / (100 + $rate), 0, PHP_ROUND_HALF_UP);

        return self::fromMinor($cents);
    }

    public static function cmp(int|float|string $left, int|float|string $right): int
    {
        return self::minor($left) <=> self::minor($right);
    }

    public static function isZero(int|float|string $value): bool
    {
        return self::minor($value) === 0;
    }

    public static function isPositive(int|float|string $value): bool
    {
        return self::minor($value) > 0;
    }

    private static function normalizeString(string $value): string
    {
        $value = trim(str_replace([',', ' '], '', $value));
        if ($value === '') {
            throw new InvalidArgumentException('Monetary amount cannot be empty.');
        }

        return $value;
    }
}
