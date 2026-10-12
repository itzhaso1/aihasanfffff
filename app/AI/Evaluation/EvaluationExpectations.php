<?php

declare(strict_types=1);

namespace App\AI\Evaluation;

final readonly class EvaluationExpectations
{
    /** @var list<string> */
    public array $requiredTrainingIds;

    /** @var list<string> */
    public array $skillKeys;

    /**
     * @param  list<string>  $requiredTrainingIds
     * @param  list<string>  $skillKeys
     */
    public function __construct(
        public ?string $language = null,
        public ?string $dialect = null,
        public ?string $dialectHint = null,
        public ?string $greetingPolicy = null,
        public bool $firstContact = false,
        array $requiredTrainingIds = [],
        array $skillKeys = [],
    ) {
        $this->requiredTrainingIds = array_values(array_unique(array_filter(
            $requiredTrainingIds,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));
        $this->skillKeys = array_values(array_unique(array_filter(
            $skillKeys,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));
    }

    public function hasPersonaSignals(): bool
    {
        return $this->language !== null
            || $this->dialect !== null
            || $this->dialectHint !== null
            || $this->greetingPolicy !== null
            || $this->firstContact;
    }

    public function hasTrainingSignals(): bool
    {
        return $this->requiredTrainingIds !== [];
    }
}
