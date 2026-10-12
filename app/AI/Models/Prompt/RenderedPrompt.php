<?php

declare(strict_types=1);

namespace App\AI\Models\Prompt;

final readonly class RenderedPrompt
{
    public function __construct(
        public string $system,
        public string $user,
    ) {
        if (trim($user) === '') {
            throw new \InvalidArgumentException('A rendered prompt needs a user message.');
        }
    }
}
