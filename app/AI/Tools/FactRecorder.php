<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Core\AiRequest;
use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\Support\Chat\CatalogAvailabilityFact;

final class FactRecorder
{
    private const MAX_PRODUCT_FACTS = 8;

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    public function facts(string $toolName, array $arguments, array $payload): array
    {
        return match ($toolName) {
            CanonicalTools::SEARCH_PRODUCTS => $this->searchFacts($payload),
            CanonicalTools::GET_PRODUCT_INTELLIGENCE => $this->intelligenceFacts($arguments, $payload),
            CanonicalTools::EVALUATE_PRODUCT_SUITABILITY => $this->evaluationFacts($arguments, $payload),
            CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT => $this->savedContextFacts($payload),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function confirmedAction(string $toolName, AiRequest $request, array $payload): ?ConfirmedAction
    {
        if ($toolName !== CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT) {
            return null;
        }
        if (! ($payload['saved'] ?? false)) {
            return null;
        }

        $reference = $request->conversationId !== null ? (string) $request->conversationId : null;

        return new ConfirmedAction(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, $reference);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function searchFacts(array $payload): array
    {
        $facts = [
            new FactRecord(CanonicalTools::SEARCH_PRODUCTS, 'count', (int) ($payload['count'] ?? 0)),
            new FactRecord(
                CanonicalTools::SEARCH_PRODUCTS,
                'result_status',
                ($payload['count'] ?? 0) === 0 ? 'empty' : 'available',
            ),
        ];
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        foreach (array_slice($products, 0, self::MAX_PRODUCT_FACTS) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['catalog_item_id'] ?? $row['item_id'] ?? null;
            $prefix = is_numeric($id) ? 'catalog_item:'.(int) $id : 'product:'.$index;
            foreach (['name', 'price', 'currency', 'stock', 'sku'] as $field) {
                if (! array_key_exists($field, $row) || ! $this->isScalar($row[$field])) {
                    continue;
                }
                $facts[] = new FactRecord(CanonicalTools::SEARCH_PRODUCTS, $prefix.'.'.$field, $row[$field]);
            }
            $availability = CatalogAvailabilityFact::token($row['availability'] ?? null);
            if ($availability !== null) {
                $facts[] = new FactRecord(CanonicalTools::SEARCH_PRODUCTS, $prefix.'.availability', $availability);
            }
            $variants = is_array($row['variants'] ?? null) ? $row['variants'] : [];
            foreach (array_slice($variants, 0, self::MAX_PRODUCT_FACTS) as $variant) {
                if (! is_array($variant) || ! is_numeric($variant['id'] ?? null)) {
                    continue;
                }
                $variantPrefix = $prefix.'.variant:'.(int) $variant['id'];
                foreach (['name', 'price', 'currency', 'stock', 'sku'] as $field) {
                    if (! array_key_exists($field, $variant) || ! $this->isScalar($variant[$field])) {
                        continue;
                    }
                    $facts[] = new FactRecord(CanonicalTools::SEARCH_PRODUCTS, $variantPrefix.'.'.$field, $variant[$field]);
                }
                if (array_key_exists('matches_question', $variant)) {
                    $facts[] = new FactRecord(
                        CanonicalTools::SEARCH_PRODUCTS,
                        $variantPrefix.'.matches_question',
                        ($variant['matches_question'] === true || $variant['matches_question'] === 1) ? 1 : 0,
                    );
                }
                $variantAvailability = CatalogAvailabilityFact::token($variant['availability'] ?? null);
                if ($variantAvailability !== null) {
                    $facts[] = new FactRecord(CanonicalTools::SEARCH_PRODUCTS, $variantPrefix.'.availability', $variantAvailability);
                }
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function intelligenceFacts(array $arguments, array $payload): array
    {
        $itemId = (int) ($arguments['item_id'] ?? $payload['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        return [
            new FactRecord(CanonicalTools::GET_PRODUCT_INTELLIGENCE, 'catalog_item:'.$itemId.'.loaded', true),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function evaluationFacts(array $arguments, array $payload): array
    {
        $itemId = (int) ($payload['item_id'] ?? $arguments['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        $evaluation = is_array($payload['evaluation'] ?? null) ? $payload['evaluation'] : [];
        $status = $evaluation['status'] ?? $evaluation['result'] ?? null;
        $facts = [
            new FactRecord(CanonicalTools::EVALUATE_PRODUCT_SUITABILITY, 'catalog_item:'.$itemId.'.evaluated', true),
        ];
        if ($this->isScalar($status)) {
            $facts[] = new FactRecord(CanonicalTools::EVALUATE_PRODUCT_SUITABILITY, 'catalog_item:'.$itemId.'.status', $status);
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<FactRecord>
     */
    private function savedContextFacts(array $payload): array
    {
        $keys = is_array($payload['keys'] ?? null) ? $payload['keys'] : [];
        $facts = [
            new FactRecord(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, 'saved', (bool) ($payload['saved'] ?? false)),
        ];
        foreach ($keys as $key) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $facts[] = new FactRecord(CanonicalTools::SAVE_CUSTOMER_COMMERCE_CONTEXT, 'key.'.$key, true);
        }

        return $facts;
    }

    private function isScalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null;
    }
}
