<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Tools;

use App\AI\Tools\CanonicalTools;
use App\AI\Tools\FactRecorder;
use App\AI\Tools\ResultSanitizer;
use PHPUnit\Framework\TestCase;

class ResultSanitizerTest extends TestCase
{
    public function test_sensitive_keys_are_stripped_and_catalog_item_id_is_kept(): void
    {
        $payload = (new ResultSanitizer)->sanitize([
            'count' => 1,
            'customer_id' => 88,
            'workspace_id' => 3,
            'products' => [[
                'catalog_item_id' => 12,
                'name' => 'فستان أسود',
                'price' => 10,
                'stock' => 2,
                'supplier_cost' => 4,
                'secret' => 'hidden',
            ]],
        ], CanonicalTools::SEARCH_PRODUCTS, true);

        $this->assertSame(12, $payload['products'][0]['catalog_item_id']);
        $this->assertSame('فستان أسود', $payload['products'][0]['name']);
        $this->assertArrayNotHasKey('customer_id', $payload);
        $this->assertArrayNotHasKey('workspace_id', $payload);
        $this->assertArrayNotHasKey('supplier_cost', $payload['products'][0]);
        $this->assertArrayNotHasKey('secret', $payload['products'][0]);
    }

    public function test_failure_payload_is_reduced_to_a_safe_message(): void
    {
        $payload = (new ResultSanitizer)->sanitize(
            ['message' => 'failed Bearer sk-abcdefghijk', 'trace' => 'x'],
            CanonicalTools::SEARCH_PRODUCTS,
            false,
        );

        $this->assertSame(['message'], array_keys($payload));
        $this->assertStringNotContainsString('sk-', $payload['message']);
    }

    public function test_fact_recorder_prefers_catalog_item_ids(): void
    {
        $facts = (new FactRecorder)->facts(CanonicalTools::SEARCH_PRODUCTS, ['query' => 'فستان'], [
            'count' => 1,
            'products' => [['catalog_item_id' => 12, 'name' => 'فستان أسود', 'price' => 10, 'availability' => 'in_stock']],
        ]);

        $name = collect($facts)->first(static fn ($fact): bool => $fact->key === 'catalog_item:12.name');

        $this->assertNotNull($name);
        $this->assertSame('فستان أسود', $name->value);
        $this->assertSame('count', $facts[0]->key);
    }

    public function test_variant_match_evidence_and_exact_availability_are_preserved(): void
    {
        $payload = (new ResultSanitizer)->sanitize([
            'count' => 1,
            'products' => [[
                'catalog_item_id' => 12,
                'name' => 'فستان أسود',
                'variant_match_status' => 'matched',
                'matched_variant_ids' => [44],
                'variants' => [[
                    'id' => 44,
                    'name' => 'وسط طويل',
                    'stock' => 0,
                    'availability' => ['label' => 'show_unavailable', 'available' => false],
                    'attributes' => ['size' => 'وسط', 'length' => 'طويل'],
                    'matches_question' => true,
                ]],
            ]],
        ], CanonicalTools::SEARCH_PRODUCTS, true);

        $product = $payload['products'][0];
        $variant = $product['variants'][0];
        $this->assertSame('matched', $product['variant_match_status']);
        $this->assertSame([44], $product['matched_variant_ids']);
        $this->assertTrue($variant['matches_question']);
        $this->assertSame('out_of_stock', $variant['availability']);
        $this->assertSame(['size' => 'وسط', 'length' => 'طويل'], $variant['attributes']);
    }
}
