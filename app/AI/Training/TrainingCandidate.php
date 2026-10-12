<?php

declare(strict_types=1);

namespace App\AI\Training;

final readonly class TrainingCandidate
{
    public function __construct(
        public TrainingDocument $document,
        public int $tier,
        public int $specificity = 0,
    ) {}

    public function isMandatory(): bool
    {
        return $this->tier === TrainingContextBuilder::ALWAYS_TIER;
    }
}
