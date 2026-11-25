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
        if (in_array($toolName, [
            CanonicalTools::SEARCH_PRODUCTS,
            CanonicalTools::GET_ACTIVE_OFFERS,
            CanonicalTools::COMPARE_PRODUCTS,
        ], true)) {
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
        $payload['products'] = array_map(function (mixed $row): array {
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
                    'base_price' => $variant['base_price'] ?? null,
                    'sale_price' => $variant['sale_price'] ?? null,
                    'compare_at_price' => $variant['compare_at_price'] ?? null,
                    'has_active_offer' => ($variant['has_active_offer'] ?? false) === true ? true : null,
                    'discount_percent' => $variant['discount_percent'] ?? null,
                    'display_price' => $variant['display_price'] ?? null,
                    'currency' => $variant['currency'] ?? null,
                    'stock' => $variant['stock'] ?? null,
                    'availability' => $this->availabilityLabel(
                        $variantAvailability,
                        $variant['stock'] ?? null,
                    ),
                    'attributes' => is_array($variant['attributes'] ?? null) ? $variant['attributes'] : null,
                    'matches_question' => $variant['matches_question'] ?? null,
                ], static fn (mixed $value): bool => $value !== null);
            }

            $matchedVariantIds = is_array($row['matched_variant_ids'] ?? null)
                ? array_values(array_filter(
                    $row['matched_variant_ids'],
                    static fn (mixed $id): bool => is_int($id) || (is_string($id) && is_numeric($id)),
                ))
                : null;

            return array_filter([
                'catalog_item_id' => $row['catalog_item_id'] ?? $row['item_id'] ?? null,
                'name' => $row['name'] ?? null,
                'description' => $row['description'] ?? null,
                'sku' => $row['sku'] ?? null,
                'brand' => $row['brand'] ?? null,
                'price' => $row['price'] ?? null,
                'base_price' => $row['base_price'] ?? null,
                'sale_price' => $row['sale_price'] ?? null,
                'compare_at_price' => $row['compare_at_price'] ?? null,
                'has_active_offer' => ($row['has_active_offer'] ?? false) === true ? true : null,
                'discount_percent' => $row['discount_percent'] ?? null,
                'display_price' => $row['display_price'] ?? null,
                'currency' => $row['currency'] ?? null,
                'stock' => $row['stock'] ?? null,
                'category' => $row['category'] ?? null,
                'availability' => $availability,
                'specifications' => is_array($row['specifications'] ?? null) && $row['specifications'] !== [] ? $row['specifications'] : null,
                'attributes' => is_array($row['attributes'] ?? null) && $row['attributes'] !== [] ? $row['attributes'] : null,
                'variants' => $variants === [] ? null : $variants,
                'has_more_variants' => ($row['has_more_variants'] ?? false) === true ? true : null,
                'variant_match_status' => $row['variant_match_status'] ?? null,
                'matched_variant_ids' => $matchedVariantIds,
            ], static fn (mixed $value): bool => $value !== null);
        }, $products);
        $payload['count'] = count($payload['products']);
        $payload['result_status'] = $payload['count'] === 0 ? 'empty' : 'available';

        return $payload;
    }

    private function availabilityLabel(mixed $availability, mixed $stock = null): ?string
    {
        $label = is_array($availability)
            ? ($availability['label'] ?? null)
            : $availability;
        if (is_string($label) && trim($label) !== '') {
            return match (strtolower(trim($label))) {
                'available', 'in_stock' => 'in_stock',
                'out_of_stock', 'unavailable', 'show_unavailable', 'hide_unavailable' => 'out_of_stock',
                default => trim($label),
            };
        }
        if (is_bool($availability)) {
            return $availability ? 'in_stock' : 'out_of_stock';
        }
        if (is_array($availability) && is_bool($availability['available'] ?? null)) {
            return $availability['available'] ? 'in_stock' : 'out_of_stock';
        }
        if (is_int($stock) || (is_string($stock) && is_numeric($stock))) {
            return (int) $stock > 0 ? 'in_stock' : 'out_of_stock';
        }

        return null;
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
