<?php

namespace App\Services\AI\Inbox\Memory;

use App\Enums\InboxAgentMemoryEvidenceType;
use App\Enums\InboxAgentMemoryScope;
use App\Enums\InboxAgentMemoryValueType;

final class InboxAgentMemoryStructuredParser
{
    public function __construct(
        private readonly InboxAgentMemoryPolicy $policy,
    ) {}

    /**
     * @return list<InboxAgentMemoryCandidate>
     */
    public function parse(string $content, int $sourceMessageId, int $sourceConversationId): array
    {
        $payload = $this->decodeJsonPayload($content);
        $rows = $payload['memories'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (! is_array($row) || count($candidates) >= $this->policy->maxCandidates()) {
                break;
            }

            try {
                $candidates[] = $this->parseRow($row, $sourceMessageId, $sourceConversationId);
            } catch (InboxAgentMemoryValidationException) {
                continue;
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content) ?? $content;
        }

        $decoded = json_decode(trim($content), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseRow(array $row, int $sourceMessageId, int $sourceConversationId): InboxAgentMemoryCandidate
    {
        $scopeRaw = trim((string) ($row['scope'] ?? ''));
        $scope = InboxAgentMemoryScope::tryFrom($scopeRaw);
        if ($scope === null) {
            throw InboxAgentMemoryValidationException::forField('scope', 'unsupported.');
        }

        $key = trim((string) ($row['key'] ?? ''));
        if ($key === '') {
            throw InboxAgentMemoryValidationException::forField('key', 'required.');
        }

        $valueTypeRaw = trim((string) ($row['value_type'] ?? 'string'));
        $valueType = InboxAgentMemoryValueType::tryFrom($valueTypeRaw) ?? InboxAgentMemoryValueType::String;

        $confidence = (float) ($row['confidence'] ?? 0);
        $evidenceRaw = trim((string) ($row['evidence_type'] ?? 'explicit'));
        $evidence = InboxAgentMemoryEvidenceType::tryFrom($evidenceRaw) ?? InboxAgentMemoryEvidenceType::Explicit;

        $value = $row['value'] ?? null;
        if ($valueType === InboxAgentMemoryValueType::String) {
            $value = trim((string) $value);
        }

        return new InboxAgentMemoryCandidate(
            scope: $scope,
            key: $key,
            value: $value,
            valueType: $valueType,
            confidence: $confidence,
            evidenceType: $evidence,
            sourceMessageId: $sourceMessageId,
            sourceConversationId: $sourceConversationId,
            reason: trim((string) ($row['reason'] ?? '')),
        );
    }
}
