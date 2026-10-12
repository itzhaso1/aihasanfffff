<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use InvalidArgumentException;

/**
 * One piece of business truth a run relied on, with the trusted source that provided it.
 */
final readonly class FactRecord
{
    public string|int|float|bool|null $value;

    public function __construct(
        public string $source,
        public string $key,
        string|int|float|bool|null $value,
    ) {
        if (trim($source) === '' || trim($key) === '') {
            throw new InvalidArgumentException('Fact source and key must not be empty.');
        }
        $this->value = is_string($value) ? SafeData::text($value) : $value;
    }

    /**
     * @return array{source: string, key: string, value: string|int|float|bool|null}
     */
    public function toArray(): array
    {
        return ['source' => $this->source, 'key' => $this->key, 'value' => $this->value];
    }
}
