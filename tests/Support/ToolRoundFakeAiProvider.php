<?php

namespace Tests\Support;

use App\Services\AI\Contracts\AiProviderInterface;

/**
 * Fake provider that can return structured tool_calls then a final reply.
 */
final class ToolRoundFakeAiProvider implements AiProviderInterface
{
    /** @var list<array<string, mixed>> */
    public array $responses = [];

    public int $calls = 0;

    public function supportsNativeToolCalling(): bool
    {
        return true;
    }

    public function generate(array $messages, string $model, float $temperature, int $maxTokens, array $toolDefinitions = []): array
    {
        $this->calls++;
        $next = array_shift($this->responses);

        if (! is_array($next)) {
            return [
                'content' => 'fallback reply',
                'tokens_used' => 3,
                'raw' => [],
            ];
        }

        return $next;
    }
}
