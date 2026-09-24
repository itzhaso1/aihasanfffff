<?php

namespace Tests\Support;

use App\Services\AI\Contracts\AiProviderInterface;
use RuntimeException;

class FakeAiProvider implements AiProviderInterface
{
    /** @var array<int, array{role:string,content:string}> */
    public array $lastMessages = [];

    public ?string $lastModel = null;

    public ?float $lastTemperature = null;

    public ?int $lastMaxTokens = null;

    public int $calls = 0;

    public string $content = 'رد تجريبي آمن';

    public int $tokensUsed = 10;

    public bool $throw = false;

    public function supportsNativeToolCalling(): bool
    {
        return false;
    }

    public function generate(array $messages, string $model, float $temperature, int $maxTokens, array $toolDefinitions = []): array
    {
        $this->calls++;
        $this->lastMessages = $messages;
        $this->lastModel = $model;
        $this->lastTemperature = $temperature;
        $this->lastMaxTokens = $maxTokens;

        if ($this->throw) {
            throw new RuntimeException('provider failed');
        }

        return [
            'content' => $this->content,
            'tokens_used' => $this->tokensUsed,
            'raw' => ['content' => $this->content],
        ];
    }

    public function capturedPrompt(): string
    {
        return collect($this->lastMessages)
            ->map(fn (array $row): string => (string) ($row['content'] ?? ''))
            ->implode("\n");
    }
}
