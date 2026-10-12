<?php

declare(strict_types=1);

namespace App\AI\Context;

use InvalidArgumentException;

final readonly class ContextMessage
{
    public function __construct(
        public string $role,
        public string $content,
        public ?int $messageId = null,
        public ?string $occurredAt = null,
        public ?string $channel = null,
    ) {
        if (! in_array($role, ['customer', 'human', 'ai', 'worker', 'agent', 'current_customer'], true)) {
            throw new InvalidArgumentException('Unsupported context message role.');
        }
        if (trim($content) === '') {
            throw new InvalidArgumentException('Context message content must not be empty.');
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
            'message_id' => $this->messageId,
            'occurred_at' => $this->occurredAt,
            'channel' => $this->channel,
        ];
    }
}
