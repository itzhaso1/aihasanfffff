<?php

declare(strict_types=1);

namespace App\AI\Training;

final readonly class TrainingScan
{
    /**
     * @param  list<TrainingSourceFile>  $files  Sorted by relative path.
     * @param  list<TrainingException>  $problems  Layout problems found while scanning.
     * @param  list<string>  $missingFolders
     */
    public function __construct(
        public array $files,
        public array $problems,
        public array $missingFolders,
    ) {}
}
