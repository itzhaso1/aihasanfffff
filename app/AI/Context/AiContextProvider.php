<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\ContextProvider;
use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Decision\FactRecord;
use App\Support\Chat\CatalogAvailabilityFact;

final class AiContextProvider implements ContextProvider
{
    public function __construct(
        private readonly AiContextBuilder $builder,
        private readonly AiContextRenderer $renderer,
    ) {}

    public function build(AiRequest $request, ResolvedEmployee $employee): ContextPayload
    {
        $context = $this->builder->build($request, $employee);

        return new ContextPayload(
            sections: $this->renderer->sections($context),
            intent: $context->intent->canonical,
            references: $context->references->references,
            facts: $this->catalogFacts($context),
            structuredContext: $context,
        );
    }

    /**
     * @return list<FactRecord>
     */
    private function catalogFacts(AiContext $context): array
    {
        if ($context->references->retrievalStatus !== 'listed') {
            return [];
        }

        $facts = [
            new FactRecord('chat_catalog', 'result_status', 'available'),
        ];
        foreach (array_slice($context->references->catalogItems, 0, 8) as $item) {
            $id = $item['catalog_item_id'] ?? null;
            if (! is_numeric($id)) {
                continue;
            }
            $prefix = 'catalog_item:'.(int) $id;
            foreach (['name', 'price', 'currency', 'stock', 'availability'] as $field) {
                $value = $item[$field] ?? null;
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    continue;
                }
                $facts[] = new FactRecord('chat_catalog', $prefix.'.'.$field, $value);
            }
            foreach ($this->variantRows($item['variants'] ?? null) as $variant) {
                $variantId = $variant['id'] ?? null;
                if (! is_numeric($variantId)) {
                    continue;
                }
                $variantPrefix = $prefix.'.variant:'.(int) $variantId;
                foreach (['name', 'price', 'currency', 'stock', 'availability'] as $field) {
                    $value = $variant[$field] ?? null;
                    if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                        continue;
                    }
                    $facts[] = new FactRecord('chat_catalog', $variantPrefix.'.'.$field, $value);
                }
                if (array_key_exists('matches_question', $variant)) {
                    $facts[] = new FactRecord(
                        'chat_catalog',
                        $variantPrefix.'.matches_question',
                        ($variant['matches_question'] === true || $variant['matches_question'] === 1) ? 1 : 0,
                    );
                }
            }
            $variantMatchStatus = $item['variant_match_status'] ?? null;
            if (is_string($variantMatchStatus) && $variantMatchStatus !== '') {
                $facts[] = new FactRecord(
                    'chat_catalog',
                    $prefix.'.variant_match_status',
                    $variantMatchStatus,
                );
                if ($variantMatchStatus === 'not_found') {
                    $facts[] = new FactRecord(
                        'chat_catalog',
                        $prefix.'.requested_variant.status',
                        'not_found',
                    );
                }
                foreach ($this->variantRows($item['variants'] ?? null) as $variant) {
                    if (($variant['matches_question'] ?? false) !== true) {
                        continue;
                    }
                    $availability = CatalogAvailabilityFact::token($variant['availability'] ?? null);
                    if ($availability !== null) {
                        $facts[] = new FactRecord(
                            'chat_catalog',
                            $prefix.'.requested_variant.availability',
                            $availability,
                        );
                    }
                    if (is_int($variant['stock'] ?? null)
                        || (is_string($variant['stock'] ?? null) && is_numeric($variant['stock']))) {
                        $facts[] = new FactRecord(
                            'chat_catalog',
                            $prefix.'.requested_variant.stock',
                            (int) $variant['stock'],
                        );
                    }
                }
            }
        }

        return $facts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function variantRows(mixed $variants): array
    {
        if (! is_array($variants)) {
            return [];
        }

        $rows = [];
        foreach ($variants as $variant) {
            if (is_array($variant)) {
                $rows[] = $variant;
            }
        }

        return $rows;
    }
}
