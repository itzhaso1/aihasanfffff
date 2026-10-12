<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Tools;

use App\AI\Tools\CanonicalTools;
use App\AI\Tools\ToolAccess;
use App\AI\Tools\ToolDefinition;
use App\AI\Tools\ToolRegistryException;
use PHPUnit\Framework\TestCase;

class ToolDefinitionTest extends TestCase
{
    public function test_canonical_save_is_a_write_and_search_is_a_read(): void
    {
        $search = CanonicalTools::searchProducts();
        $save = CanonicalTools::saveCustomerCommerceContext();

        $this->assertFalse($search->writes);
        $this->assertSame(ToolAccess::Read, $search->access);
        $this->assertTrue($save->writes);
        $this->assertSame(ToolAccess::Write, $save->access);
        $this->assertSame('save_customer_commerce_context', $save->name);
    }

    public function test_fingerprint_is_deterministic_and_content_sensitive(): void
    {
        $a = CanonicalTools::searchProducts();
        $b = CanonicalTools::searchProducts();
        $changed = new ToolDefinition(
            name: $a->name,
            description: 'changed',
            category: $a->category,
            inputSchema: $a->inputSchema,
        );

        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame($a->fingerprint(), $changed->fingerprint());
    }

    public function test_invalid_name_and_write_access_mismatch_are_rejected(): void
    {
        try {
            new ToolDefinition('Search Products', 'x', 'catalog');
            $this->fail('Invalid name was accepted.');
        } catch (ToolRegistryException) {
            $this->assertTrue(true);
        }

        $this->expectException(ToolRegistryException::class);
        new ToolDefinition('save_x', 'x', 'catalog', writes: true, access: ToolAccess::Read);
    }
}
