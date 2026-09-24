<?php

namespace App\Services\AI\Inbox\Tools;

use App\Services\AI\Inbox\InboxAgentException;

/**
 * Extracts provider-neutral tool calls from a provider generate() result.
 *
 * Providers may optionally include a top-level `tool_calls` array. When absent,
 * no tool calls are inferred from free-text content.
 */
final class InboxAgentProviderToolCallParser
{
    public function __construct(
        private readonly InboxAgentToolArgumentValidator $argumentValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $providerResult
     * @return list<InboxAgentToolCall>
     */
    public function parse(array $providerResult): array
    {
        $rawCalls = $providerResult['tool_calls'] ?? null;
        if (! is_array($rawCalls) || $rawCalls === []) {
            return [];
        }

        $calls = [];

        foreach ($rawCalls as $index => $row) {
            if (! is_array($row)) {
                throw InboxAgentException::malformedResult('Tool call entry is not an array.');
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                throw InboxAgentException::malformedResult('Tool call is missing a name.');
            }

            $callId = trim((string) ($row['call_id'] ?? $row['id'] ?? 'call_'.$index));
            if ($callId === '') {
                $callId = 'call_'.$index;
            }

            try {
                $arguments = $this->argumentValidator->decodeArguments($row['arguments'] ?? []);
            } catch (\InvalidArgumentException $exception) {
                throw InboxAgentException::malformedResult('Tool call arguments are malformed: '.$exception->getMessage());
            }

            $providerMetadata = is_array($row['provider_metadata'] ?? null) ? $row['provider_metadata'] : [];

            $calls[] = new InboxAgentToolCall(
                toolName: $name,
                callId: $callId,
                arguments: $arguments,
                providerMetadata: $providerMetadata,
            );
        }

        return $calls;
    }
}
