<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use InvalidArgumentException;

final readonly class TrainingVersion
{
    public function __construct(
        public string $id,
        public string $version,
    ) {
        if (trim($id) === '' || trim($version) === '') {
            throw new InvalidArgumentException('Training id and version must not be empty.');
        }
    }

    /**
     * @return array{id: string, version: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version];
    }
}
