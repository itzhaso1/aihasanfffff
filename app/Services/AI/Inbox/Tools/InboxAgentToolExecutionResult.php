<?php

namespace App\Services\AI\Inbox\Tools;

/**
 * Provider-neutral tool execution outcome (safe to serialize back to the model).
 */
final class InboxAgentToolExecutionResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $toolName,
        public readonly string $callId,
        public readonly bool $success,
        public readonly array $payload,
        public readonly ?string $errorCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function success(string $toolName, string $callId, array $payload): self
    {
        return new self($toolName, $callId, true, $payload);
    }

    public static function failure(string $toolName, string $callId, string $errorCode, string $message): self
    {
        return new self($toolName, $callId, false, ['message' => $message], $errorCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelPayload(): array
    {
        return [
            'tool' => $this->toolName,
            'call_id' => $this->callId,
            'success' => $this->success,
            'result' => $this->payload,
            'error_code' => $this->errorCode,
        ];
    }
}
