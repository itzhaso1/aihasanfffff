<?php

namespace App\Services\Cashier\Assistant;

final class CashierAssistantToolResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $tool,
        public readonly bool $available,
        public readonly array $data,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function available(string $tool, array $payload): self
    {
        return new self($tool, true, $payload);
    }

    public static function denied(string $tool, string $reason = 'permission_denied'): self
    {
        return new self($tool, false, ['reason' => $reason], $reason);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function unavailable(string $tool, string $reason, array $data = []): self
    {
        return new self($tool, false, array_merge(['reason' => $reason], $data), $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toFactArray(): array
    {
        return [
            'tool' => $this->tool,
            'available' => $this->available,
            'data' => $this->data,
            'reason' => $this->reason,
        ];
    }
}
