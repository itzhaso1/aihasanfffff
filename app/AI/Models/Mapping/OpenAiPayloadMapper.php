<?php

declare(strict_types=1);

namespace App\AI\Models\Mapping;

use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ToolExchange;
use App\AI\Models\Prompt\PromptMessageRenderer;
use App\AI\Models\ResolvedModelSettings;

final class OpenAiPayloadMapper
{
    public function __construct(
        private readonly PromptMessageRenderer $renderer = new PromptMessageRenderer,
        private readonly ToolSchemaMapper $tools = new ToolSchemaMapper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(ResolvedModelSettings $settings, ModelRequest $request): array
    {
        $rendered = $this->renderer->render($request->prompt);
        $messages = [];
        if ($rendered->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $rendered->system];
        }
        $messages[] = ['role' => 'user', 'content' => $rendered->user];

        foreach ($request->toolExchanges as $exchange) {
            $messages[] = $this->assistantToolCall($exchange);
            $messages[] = $this->toolResult($exchange);
        }

        $payload = [
            'model' => $settings->model,
            'messages' => $messages,
            'temperature' => $settings->temperature,
            'max_tokens' => $settings->maxOutputTokens,
        ];

        if ($request->tools !== []) {
            $payload['tools'] = $this->tools->openaiTools($request->tools);
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantToolCall(ToolExchange $exchange): array
    {
        return [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => $exchange->call->callId,
                'type' => 'function',
                'function' => [
                    'name' => $exchange->call->name,
                    'arguments' => json_encode($exchange->call->arguments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolResult(ToolExchange $exchange): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $exchange->call->callId,
            'content' => json_encode($exchange->result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }
}
