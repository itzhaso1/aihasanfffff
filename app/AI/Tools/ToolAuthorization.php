<?php

declare(strict_types=1);

namespace App\AI\Tools;

final readonly class ToolAuthorization
{
    public function __construct(
        public bool $allowed,
        public ?string $denial = null,
        public int $maxCallsPerRun = 1,
    ) {
        if ($allowed && $denial !== null) {
            throw ToolRegistryException::invalid('An allowed tool cannot carry a denial.');
        }
        if (! $allowed && ($denial === null || trim($denial) === '')) {
            throw ToolRegistryException::invalid('A denied tool must carry a denial code.');
        }
        if ($maxCallsPerRun < 1) {
            throw ToolRegistryException::invalid('Authorized max calls must be at least 1.');
        }
    }

    public static function allow(int $maxCallsPerRun): self
    {
        return new self(true, null, $maxCallsPerRun);
    }

    public static function deny(string $denial): self
    {
        return new self(false, $denial);
    }
}
