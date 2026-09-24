<?php

namespace App\Services\AI\Inbox\Memory;

use App\Enums\InboxAgentMemoryValueType;

final class InboxAgentMemoryValueCodec
{
    /**
     * @return array{v: mixed}
     */
    public function encode(mixed $value, InboxAgentMemoryValueType $type): array
    {
        return ['v' => $value];
    }

    public function decode(array $stored, InboxAgentMemoryValueType $type): mixed
    {
        return $stored['v'] ?? null;
    }

    public function toDisplayString(mixed $value, InboxAgentMemoryValueType $type): string
    {
        return match ($type) {
            InboxAgentMemoryValueType::Boolean => $value ? 'true' : 'false',
            InboxAgentMemoryValueType::Json => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '',
            InboxAgentMemoryValueType::Number => is_numeric($value) ? (string) $value : '',
            default => trim((string) $value),
        };
    }
}
