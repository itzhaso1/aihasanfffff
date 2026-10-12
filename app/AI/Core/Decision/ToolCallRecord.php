<?php

declare(strict_types=1);

namespace App\AI\Core\Decision;

use InvalidArgumentException;

final readonly class ToolCallRecord
{
    public const NOT_OFFERED = 'tool_not_offered';

    /** @var array<array-key, mixed> */
    public array $arguments;

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function __construct(
        public string $toolName,
        public string $callId,
        array $arguments,
        public bool $success,
        public ?string $errorCode = null,
    ) {
        if (trim($toolName) === '' || trim($callId) === '') {
            throw new InvalidArgumentException('Tool name and call id must not be empty.');
        }
        if ($success && $errorCode !== null) {
            throw new InvalidArgumentException('A successful tool call cannot carry an error code.');
        }
        $this->arguments = SafeData::sanitize($arguments);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool' => $this->toolName,
            'call_id' => $this->callId,
            'arguments' => $this->arguments,
            'success' => $this->success,
            'error_code' => $this->errorCode,
        ];
    }
}
