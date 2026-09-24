<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\Inbox\Tools\InboxAgentToolDefinition;

interface AiProviderInterface
{
    /**
     * @param  array<int, array{role:string,content:string,tool_calls?:array<int,array<string,mixed>>,tool_name?:string,tool_call_id?:string}>  $messages
     * @param  list<InboxAgentToolDefinition>  $toolDefinitions
     * @return array{content:string,tokens_used:int,raw:array<string,mixed>,tool_calls?:list<array<string,mixed>>}
     */
    public function generate(
        array $messages,
        string $model,
        float $temperature,
        int $maxTokens,
        array $toolDefinitions = [],
    ): array;

    public function supportsNativeToolCalling(): bool;
}
