<?php

namespace App\Support\Observability;

final class Percentile
{
    /**
     * @param  list<int>  $sortedValues
     */
    public static function calculate(array $sortedValues, int $percentile): int
    {
        if ($sortedValues === []) {
            return 0;
        }

        $percentile = max(0, min(100, $percentile));
        $index = (int) ceil(($percentile / 100) * count($sortedValues)) - 1;
        $index = max(0, min(count($sortedValues) - 1, $index));

        return (int) $sortedValues[$index];
    }
}
