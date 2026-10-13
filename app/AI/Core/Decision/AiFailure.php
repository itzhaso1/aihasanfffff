<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use App\AI\Core\AiFailureCode;
use App\AI\Core\AiRuntimeStage;
use App\AI\Core\Contracts\StructuredProviderFailure;
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
        public ?string $providerErrorCode = null,
        public ?int $httpStatus = null,
    ) {}

    public static function fromThrowable(AiRuntimeStage $stage, AiFailureCode $code, Throwable $exception): self
    {
        $class = $exception::class;
        $position = strrpos($class, '\\');
        $providerErrorCode = null;
        $httpStatus = null;

        if ($exception instanceof StructuredProviderFailure) {
            $providerErrorCode = $exception->providerErrorCode();
            $httpStatus = $exception->httpStatus();
        }

        return new self(
            $stage,
            $code,
            $position === false ? $class : substr($class, $position + 1),
            $providerErrorCode,
            $httpStatus,
        );
    }

    /**
     * @return array{stage: string, code: string, exception_type: ?string, provider_error_code?: string, http_status?: int}
     */
    public function toArray(): array
    {
        $payload = [
            'stage' => $this->stage->value,
            'code' => $this->code->value,
            'exception_type' => $this->exceptionType,
        ];

        if ($this->providerErrorCode !== null) {
            $payload['provider_error_code'] = $this->providerErrorCode;
        }

        if ($this->httpStatus !== null) {
            $payload['http_status'] = $this->httpStatus;
        }

        return $payload;
    }
}
