<?php

namespace App\Services\AI\Inbox\Tools;

use App\Services\AI\AiSafety;

/**
 * Appends provider-neutral tool round messages to the conversation stack.
 */
final class InboxAgentToolMessageBuilder
{
    public function __construct(
        private readonly AiSafety $safety,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  list<InboxAgentToolCall>  $toolCalls
     * @param  list<InboxAgentToolExecutionResult>  $results
     * @return array<int, array<string, mixed>>
     */
    public function appendToolRound(
        array $messages,
        array $toolCalls,
        array $results,
        string $assistantContent = '',
    ): array {
        $toolCallPayload = array_map(static function (InboxAgentToolCall $call): array {
            $payload = [
                'name' => $call->toolName,
                'call_id' => $call->callId,
                'arguments' => $call->arguments,
            ];

            if ($call->providerMetadata !== []) {
                $payload['provider_metadata'] = $call->providerMetadata;
            }

            return $payload;
        }, $toolCalls);

        $messages[] = [
            'role' => 'assistant',
            'content' => $this->safety->redactSecrets(trim($assistantContent)),
            'tool_calls' => $toolCallPayload,
        ];

        foreach ($results as $result) {
            $messages[] = [
                'role' => 'tool',
                'content' => $this->safety->redactSecrets(json_encode($result->toModelPayload(), JSON_UNESCAPED_UNICODE) ?: '{}'),
                'tool_name' => $result->toolName,
                'tool_call_id' => $result->callId,
            ];
        }

        return $messages;
    }
}
