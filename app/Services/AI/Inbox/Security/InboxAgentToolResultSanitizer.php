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
        $payload['products'] = array_map(
            fn (mixed $row): array => $this->sanitizeProductRow($row),
            $products,
        );

        return $payload;
    }

    /**
     * Preserve the exact variant evidence needed by the assistant while
     * dropping fields that are not customer-safe.
     *
     * @return array<string, mixed>
     */
    private function sanitizeProductRow(mixed $row): array
    {
        if (! is_array($row)) {
            return [];
        }

        $variants = [];
        foreach (array_slice(
            is_array($row['variants'] ?? null) ? $row['variants'] : [],
            0,
            8,
        ) as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $variants[] = array_filter([
                'id' => $this->positiveInteger($variant['id'] ?? null),
                'name' => $variant['name'] ?? null,
                'price' => $variant['price'] ?? null,
                'display_price' => $variant['display_price'] ?? null,
                'currency' => $variant['currency'] ?? null,
                'stock' => $variant['stock'] ?? null,
                'availability' => $this->availabilityLabel(
                    $variant['availability'] ?? null,
                    $variant['stock'] ?? null,
                ),
                'attributes' => is_array($variant['attributes'] ?? null)
                    ? $variant['attributes']
                    : null,
                'matches_question' => $variant['matches_question'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $matchedVariantIds = is_array($row['matched_variant_ids'] ?? null)
            ? array_values(array_filter(
                array_map(
                    fn (mixed $id): ?int => $this->positiveInteger($id),
                    $row['matched_variant_ids'],
                ),
            ))
            : null;
        $variantMatchStatus = $row['variant_match_status'] ?? null;
        if (! in_array($variantMatchStatus, ['matched', 'not_found', 'not_requested'], true)) {
            $variantMatchStatus = null;
        }

        return array_filter([
            'catalog_item_id' => $this->positiveInteger($row['catalog_item_id'] ?? null),
            'name' => $row['name'] ?? null,
            'description' => $row['description'] ?? null,
            'sku' => $row['sku'] ?? null,
            'price' => $row['price'] ?? null,
            'display_price' => $row['display_price'] ?? null,
            'price_display' => $row['price_display'] ?? null,
            'currency' => $row['currency'] ?? null,
            'stock' => $row['stock'] ?? null,
            'category' => $row['category'] ?? null,
            'availability' => $this->availabilityLabel(
                $row['availability'] ?? null,
                $row['stock'] ?? null,
            ),
            'availability_message' => $row['availability_message'] ?? null,
            'attributes' => is_array($row['attributes'] ?? null) ? $row['attributes'] : null,
            'variants' => $variants === [] ? null : $variants,
            'has_more_variants' => ($row['has_more_variants'] ?? false) === true ? true : null,
            'variant_match_status' => $variantMatchStatus,
            'matched_variant_ids' => $matchedVariantIds,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function availabilityLabel(mixed $availability, mixed $stock = null): ?string
    {
        if (is_array($availability)) {
            if (is_string($availability['label'] ?? null)
                && trim($availability['label']) !== '') {
                return $this->normalizeAvailabilityLabel($availability['label']);
            }
            if (is_bool($availability['available'] ?? null)) {
                return $availability['available'] ? 'in_stock' : 'out_of_stock';
            }
        }
        if (is_string($availability) && trim($availability) !== '') {
            return $this->normalizeAvailabilityLabel($availability);
        }
        if (is_bool($availability)) {
            return $availability ? 'in_stock' : 'out_of_stock';
        }
        if (is_int($stock) || (is_string($stock) && is_numeric($stock))) {
            return (int) $stock > 0 ? 'in_stock' : 'out_of_stock';
        }

        return null;
    }

    private function normalizeAvailabilityLabel(string $label): string
    {
        return match (strtolower(trim($label))) {
            'available', 'in_stock' => 'in_stock',
            'out_of_stock', 'unavailable', 'show_unavailable', 'hide_unavailable' => 'out_of_stock',
            default => trim($label),
        };
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
