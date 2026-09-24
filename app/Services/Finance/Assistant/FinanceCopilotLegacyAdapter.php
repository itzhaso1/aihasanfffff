<?php

namespace App\Services\Finance\Assistant;

/**
 * Maps unified assistant payloads to the legacy finance copilot JSON shape.
 *
 * @deprecated Remove after all clients migrate to /assistant/ask.
 */
final class FinanceCopilotLegacyAdapter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function adapt(array $payload): array
    {
        $period = is_array($payload['period'] ?? null) ? $payload['period'] : null;

        return [
            'answer' => (string) ($payload['answer'] ?? ''),
            'data' => $this->summarizeFacts(is_array($payload['facts'] ?? null) ? $payload['facts'] : []),
            'range' => $period !== null ? [
                'from' => $period['from'] ?? null,
                'to' => $period['to'] ?? null,
            ] : null,
            'preview_action' => null,
            'requires_confirmation' => false,
            'conversation_id' => $payload['conversation_id'] ?? null,
            'tools_used' => is_array($payload['tools_used'] ?? null) ? $payload['tools_used'] : [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @return array<string, mixed>
     */
    private function summarizeFacts(array $facts): array
    {
        $summary = [];

        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $tool = (string) ($fact['tool'] ?? '');
            $data = is_array($fact['data'] ?? null) ? $fact['data'] : [];
            if ($tool === '') {
                continue;
            }

            $summary[$tool] = array_filter([
                'available' => $fact['available'] ?? null,
                'value' => $data['value'] ?? null,
                'status' => $data['status'] ?? null,
                'source' => $data['source'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $summary;
    }
}
