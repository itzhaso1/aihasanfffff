<?php

declare(strict_types=1);

namespace App\AI\Training;

final readonly class TrainingLintIssue
{
    public function __construct(
        public TrainingIssueCode $code,
        public ?string $source,
        public string $message,
    ) {}

    public static function fromException(TrainingException $exception): self
    {
        $prefix = $exception->source !== null ? $exception->source.': ' : '';

        return new self($exception->issue, $exception->source, substr($exception->getMessage(), strlen($prefix)));
    }

    /**
     * @return array{code: string, source: ?string, message: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'source' => $this->source, 'message' => $this->message];
    }
}
