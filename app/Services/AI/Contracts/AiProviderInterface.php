<?php

namespace App\Services\AI\Contracts;

interface AiProviderInterface
{
    /**
     * @param  array<int, array{role:string,content:string}>  $messages
     * @return array{content:string,tokens_used:int,raw:array<string,mixed>}
     */
    public function generate(array $messages, string $model, float $temperature, int $maxTokens): array;
}
