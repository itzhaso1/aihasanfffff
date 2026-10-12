<?php

declare(strict_types=1);

namespace App\AI\Training;

final readonly class TrainingLintReport
{
    /**
     * @param  list<TrainingLintIssue>  $issues
     */
    public function __construct(
        public array $issues,
        public int $documentCount,
    ) {}

    public function passed(): bool
    {
        return $this->issues === [];
    }

    /**
     * @return list<TrainingIssueCode>
     */
    public function codes(): array
    {
        $values = array_values(array_unique(
            array_map(static fn (TrainingLintIssue $issue): string => $issue->code->value, $this->issues),
        ));

        return array_map(static fn (string $value): TrainingIssueCode => TrainingIssueCode::from($value), $values);
    }

    public function has(TrainingIssueCode $code): bool
    {
        return in_array($code, $this->codes(), true);
    }
}
