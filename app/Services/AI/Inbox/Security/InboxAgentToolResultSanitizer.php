<?php

namespace App\Services\AI\Inbox\Security;

use App\Services\AI\AiSafety;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutionResult;

final class InboxAgentToolResultSanitizer
{
    /** @var list<string> */
    private const STRIP_KEYS = [
        'workspace_id',
        'tenant_id',
        'customer_id',
        'conversation_id',
        'internal_notes',
        'supplier_cost',
        'credentials',
        'api_key',
        'password',
        'secret',
    ];

    public function __construct(
        private readonly AiSafety $safety,
        private readonly InboxAgentSecurityPolicy $policy,
    ) {}

    public function sanitize(InboxAgentToolExecutionResult $result, string $toolName): InboxAgentToolExecutionResult
    {
        if (! $result->success) {
            return new InboxAgentToolExecutionResult(
                toolName: $result->toolName,
                callId: $result->callId,
                success: false,
                payload: ['message' => $this->safePublicMessage($result->payload['message'] ?? 'Tool failed.')],
                errorCode: $result->errorCode,
            );
        }

        $payload = $this->stripSensitiveKeys($result->payload);
        if ($toolName === 'search_products') {
            $payload = $this->sanitizeSearchProducts($payload);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';
        if (mb_strlen($encoded) > $this->policy->maxToolResultChars()) {
            $payload = ['message' => 'Tool result truncated for safety.'];
        }

        return new InboxAgentToolExecutionResult(
            toolName: $result->toolName,
            callId: $result->callId,
            success: true,
            payload: $payload,
            errorCode: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeSearchProducts(array $payload): array
    {
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        $payload['products'] = array_map(static function ($row): array {
            if (! is_array($row)) {
                return [];
            }

            return array_filter([
                'name' => $row['name'] ?? null,
                'sku' => $row['sku'] ?? null,
                'price' => $row['price'] ?? null,
                'currency' => $row['currency'] ?? null,
                'stock' => $row['stock'] ?? null,
                'category' => $row['category'] ?? null,
                'availability' => isset($row['stock']) ? (((int) $row['stock']) > 0 ? 'in_stock' : 'out_of_stock') : null,
            ], static fn ($value) => $value !== null);
        }, $products);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripSensitiveKeys(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::STRIP_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->stripSensitiveKeys($value);

                continue;
            }

            if (is_string($value)) {
                $clean[$key] = $this->safety->redactSecrets($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function safePublicMessage(mixed $message): string
    {
        return $this->safety->redactSecrets(trim((string) $message));
    }
}
