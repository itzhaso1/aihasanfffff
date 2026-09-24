<?php

namespace App\Services\Finance\Assistant\Conversation;

final class FinanceAgentResultSafeEncoder
{
    /**
     * @param  list<array<string, mixed>>  $facts
     * @return list<array<string, mixed>>
     */
    public function facts(array $facts): array
    {
        $safe = [];
        foreach ($facts as $fact) {
            if (is_array($fact)) {
                $safe[] = $this->fact($fact);
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array<string, mixed>
     */
    public function fact(array $fact): array
    {
        return [
            'tool' => (string) ($fact['tool'] ?? ''),
            'available' => (bool) ($fact['available'] ?? false),
            'reason' => $fact['reason'] ?? null,
            'data' => $this->strip((array) ($fact['data'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function stripResult(array $data): array
    {
        return $this->strip($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function strip(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);
            if (
                $name === 'id'
                || str_ends_with($name, '_id')
                || in_array($name, ['workspace', 'tenant', 'role', 'permission', 'permissions', 'secret', 'token', 'key'], true)
            ) {
                continue;
            }
            if (is_array($value)) {
                $value = $this->strip($value);
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}
