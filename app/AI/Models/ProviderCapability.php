<?php

declare(strict_types=1);

namespace App\AI\Models;

final readonly class ProviderCapability
{
    public function __construct(
        public bool $nativeTools = true,
        public bool $systemInstruction = true,
        public bool $thinkingBudget = false,
    ) {}

    public static function gemini(): self
    {
        return new self(nativeTools: true, systemInstruction: true, thinkingBudget: true);
    }

    public static function openai(): self
    {
        return new self(nativeTools: true, systemInstruction: true, thinkingBudget: false);
    }
}
