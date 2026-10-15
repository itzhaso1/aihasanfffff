<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Core\Decision\SafeData;

final class ResultSanitizer
{
    private const MAX_RESULT_CHARS = 8000;

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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload, string $toolName, bool $success): array
    {
        if (! $success) {
            $message = $payload['message'] ?? 'Tool failed.';

            return ['message' => SafeData::text(trim((string) $message))];
        }

        $clean = $this->stripSensitiveKeys($payload);
        if ($toolName === CanonicalTools::SEARCH_PRODUCTS) {
            $clean = $this->sanitizeSearchProducts($clean);
        }

        $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE) ?: '';
        if (mb_strlen($encoded) > self::MAX_RESULT_CHARS) {
            return ['message' => 'Tool result truncated for safety.'];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeSearchProducts(array $payload): array
    {
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        $payload['products'] = array_map(static function (mixed $row): array {
            if (! is_array($row)) {
                return [];
            }

            $availability = $row['availability'] ?? null;
            if ($availability === null && isset($row['stock'])) {
                $availability = ((int) $row['stock']) > 0 ? 'in_stock' : 'out_of_stock';
            }

            $variants = [];
            foreach (array_slice(is_array($row['variants'] ?? null) ? $row['variants'] : [], 0, 8) as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $variantAvailability = $variant['availability'] ?? null;
                $variants[] = array_filter([
                    'id' => $variant['id'] ?? null,
                    'name' => $variant['name'] ?? null,
                    'price' => $variant['price'] ?? null,
                    'currency' => $variant['currency'] ?? null,
                    'stock' => $variant['stock'] ?? null,
                    'availability' => is_array($variantAvailability)
                        ? ($variantAvailability['label'] ?? $variantAvailability['available'] ?? null)
                        : $variantAvailability,
                    'attributes' => is_array($variant['attributes'] ?? null) ? $variant['attributes'] : null,
                    'matches_question' => $variant['matches_question'] ?? null,
                ], static fn (mixed $value): bool => $value !== null);
            }

            return array_filter([
                'catalog_item_id' => $row['catalog_item_id'] ?? $row['item_id'] ?? null,
                'name' => $row['name'] ?? null,
                'sku' => $row['sku'] ?? null,
                'price' => $row['price'] ?? null,
                'display_price' => $row['display_price'] ?? null,
                'currency' => $row['currency'] ?? null,
                'stock' => $row['stock'] ?? null,
                'category' => $row['category'] ?? null,
                'availability' => $availability,
                'variants' => $variants === [] ? null : $variants,
                'has_more_variants' => ($row['has_more_variants'] ?? false) === true ? true : null,
            ], static fn (mixed $value): bool => $value !== null);
        }, $products);
        $payload['count'] = count($payload['products']);
        $payload['result_status'] = $payload['count'] === 0 ? 'empty' : 'available';

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
                $clean[$key] = SafeData::text($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
