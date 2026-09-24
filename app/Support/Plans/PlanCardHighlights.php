<?php

namespace App\Support\Plans;

use App\Models\Plan;

final class PlanCardHighlights
{
    /**
     * Customer-facing Arabic highlights that the plan actually entitles.
     *
     * @return list<string>
     */
    public static function labels(Plan $plan): array
    {
        $features = self::featureKeys($plan);
        $tier = strtolower((string) ($plan->tier ?: $plan->code));
        $rows = config('plans.card_highlights.'.$tier, []);
        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $labels = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $all = $row['all'] ?? [];
            $any = $row['any'] ?? [];
            if (is_array($all) && $all !== [] && ! self::hasAll($features, $all)) {
                continue;
            }
            if (is_array($any) && $any !== [] && ! self::hasAny($features, $any)) {
                continue;
            }

            $labels[] = $label;
        }

        return array_values(array_unique($labels));
    }

    /**
     * @return list<string>
     */
    private static function featureKeys(Plan $plan): array
    {
        $features = is_array($plan->features) ? $plan->features : [];

        return array_values(array_filter(array_map(
            static fn (mixed $feature): string => is_string($feature) ? trim($feature) : '',
            $features,
        )));
    }

    /**
     * @param  list<string>  $features
     * @param  list<mixed>  $keys
     */
    private static function hasAny(array $features, array $keys): bool
    {
        foreach ($keys as $key) {
            if (is_string($key) && self::hasFeature($features, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $features
     * @param  list<mixed>  $keys
     */
    private static function hasAll(array $features, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! is_string($key) || ! self::hasFeature($features, $key)) {
                return false;
            }
        }

        return $keys !== [];
    }

    /**
     * @param  list<string>  $features
     */
    private static function hasFeature(array $features, string $key): bool
    {
        if (in_array($key, $features, true)) {
            return true;
        }

        $aliases = config('plans.feature_aliases.'.$key, []);
        if (! is_array($aliases)) {
            return false;
        }

        foreach ($aliases as $alias) {
            if (is_string($alias) && in_array($alias, $features, true)) {
                return true;
            }
        }

        return false;
    }
}
