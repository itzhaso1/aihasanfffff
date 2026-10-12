<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Selector values of a document. A document matches a query when every dimension it
 * declares shares at least one value with the query (AND across, OR within).
 */
final readonly class TrainingAppliesTo
{
    /** @var array<string, list<string>> Dimension value => sorted selector values. */
    private array $values;

    /**
     * @param  array<string, list<string>>  $values
     */
    public function __construct(array $values = [])
    {
        $clean = [];
        foreach (TrainingDimension::cases() as $dimension) {
            $list = $values[$dimension->value] ?? [];
            if ($list !== []) {
                $list = array_values(array_unique($list));
                sort($list, SORT_STRING);
                $clean[$dimension->value] = $list;
            }
        }
        $this->values = $clean;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return list<string>
     */
    public function values(TrainingDimension $dimension): array
    {
        return $this->values[$dimension->value] ?? [];
    }

    /**
     * Dimensions the query satisfies, or null when any declared dimension is unmet.
     *
     * @return ?list<TrainingDimension>
     */
    public function matchedDimensions(TrainingQuery $query): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        $matched = [];
        foreach (TrainingDimension::cases() as $dimension) {
            $declared = $this->values($dimension);
            if ($declared === []) {
                continue;
            }
            if (array_intersect($declared, $query->values($dimension)) === []) {
                return null;
            }
            $matched[] = $dimension;
        }

        return $matched;
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
