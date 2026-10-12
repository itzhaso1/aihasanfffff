<?php

declare(strict_types=1);

namespace App\AI\Core\Pipeline;

use App\AI\Core\AiFailureCode;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Decision\AiFailure;
use RuntimeException;
use Throwable;

/**
 * Carries the stage a dependency failed in. The previous exception is kept for the
 * PHP stack only; decisions store the safe summary from {@see self::toFailure()}.
 */
final class AiStageFailure extends RuntimeException
{
    public function __construct(
        public readonly AiRuntimeStage $stage,
        public readonly AiFailureCode $failureCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct('AI runtime stage failed: '.$stage->value, 0, $previous);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function wrap(
        AiRuntimeStage $stage,
        callable $operation,
        AiFailureCode $code = AiFailureCode::DependencyFailed,
    ): mixed {
        try {
            return $operation();
        } catch (AiStageFailure $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            throw new self($stage, $code, $exception);
        }
    }

    public function toFailure(): AiFailure
    {
        $previous = $this->getPrevious();

        return $previous === null
            ? new AiFailure($this->stage, $this->failureCode)
            : AiFailure::fromThrowable($this->stage, $this->failureCode, $previous);
    }
}
