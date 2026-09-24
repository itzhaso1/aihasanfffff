<?php

namespace Tests\Support;

class FailOnceFakeAiProvider extends FakeAiProvider
{
    public function generate(array $messages, string $model, float $temperature, int $maxTokens, array $toolDefinitions = []): array
    {
        $this->calls++;

        if ($this->calls === 1) {
            throw new \RuntimeException('provider failed');
        }

        $this->lastMessages = $messages;
        $this->lastModel = $model;
        $this->lastTemperature = $temperature;
        $this->lastMaxTokens = $maxTokens;

        return [
            'content' => $this->content,
            'tokens_used' => $this->tokensUsed,
            'raw' => ['content' => $this->content],
        ];
    }
}
