<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use App\AI\Core\AiFailureCode;
use App\AI\Core\AiRuntimeStage;
use Throwable;

/**
 * Safe failure summary: stage, code and exception class only. Exception messages are
 * never stored because they can contain customer text, SQL or credentials.
 */
final readonly class AiFailure
{
    public function __construct(
        public AiRuntimeStage $stage,
        public AiFailureCode $code,
        public ?string $exceptionType = null,
    ) {}

    public static function fromThrowable(AiRuntimeStage $stage, AiFailureCode $code, Throwable $exception): self
    {
        $class = $exception::class;
        $position = strrpos($class, '\\');

        return new self($stage, $code, $position === false ? $class : substr($class, $position + 1));
    }

    /**
     * @return array{stage: string, code: string, exception_type: ?string}
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'code' => $this->code->value,
            'exception_type' => $this->exceptionType,
        ];
    }
}
