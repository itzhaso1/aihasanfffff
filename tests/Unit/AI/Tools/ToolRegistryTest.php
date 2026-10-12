<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Tools;

use App\AI\Tools\CanonicalTools;
use App\AI\Tools\ToolRegistry;
use App\AI\Tools\ToolRegistryException;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Tools\FakeRuntimeTool;

class ToolRegistryTest extends TestCase
{
    public function test_definitions_are_sorted_and_duplicate_names_are_rejected(): void
    {
        $registry = new ToolRegistry([
            new FakeRuntimeTool(CanonicalTools::saveCustomerCommerceContext()),
            new FakeRuntimeTool(CanonicalTools::searchProducts()),
        ]);

        $this->assertSame(
            ['save_customer_commerce_context', 'search_products'],
            array_map(static fn ($definition): string => $definition->name, $registry->definitions()),
        );
        $this->assertSame($registry->fingerprint(), (new ToolRegistry([
            new FakeRuntimeTool(CanonicalTools::searchProducts()),
            new FakeRuntimeTool(CanonicalTools::saveCustomerCommerceContext()),
        ]))->fingerprint());

        $this->expectException(ToolRegistryException::class);
        $registry->register(new FakeRuntimeTool(CanonicalTools::searchProducts()));
    }

    public function test_unknown_tool_resolution_is_rejected(): void
    {
        $this->expectException(ToolRegistryException::class);
        (new ToolRegistry)->get('missing_tool');
    }
}
