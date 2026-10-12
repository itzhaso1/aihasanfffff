<?php

declare(strict_types=1);

namespace App\AI\Core\Support;

use InvalidArgumentException;

final class TypedList
{
    /**
     * @template T of object
     *
     * @param  array<array-key, mixed>  $items
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public static function of(array $items, string $class, string $label): array
    {
        foreach ($items as $item) {
            if (! $item instanceof $class) {
                throw new InvalidArgumentException($label.' must only contain '.$class.' instances.');
            }
        }

        return array_values($items);
    }
}
