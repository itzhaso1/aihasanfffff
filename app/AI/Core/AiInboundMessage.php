<?php

declare(strict_types=1);

namespace App\AI\Core;

final readonly class AiInboundMessage
{
    public function __construct(
        public string $text,
        public ?int $messageId = null,
    ) {
        if (trim($text) === '') {
            throw InvalidAiRequest::because('message text must not be empty');
        }
        if ($messageId !== null && $messageId <= 0) {
            throw InvalidAiRequest::because('message id must be positive');
        }
    }
}
