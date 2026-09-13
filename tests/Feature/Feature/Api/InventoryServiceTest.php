<?php

namespace Tests\Feature\Feature\Api;

use App\Models\Product;
use App\Models\Workspace;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_service_blocks_overselling(): void
    {
        $workspace = Workspace::factory()->create();
        $product = Product::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Limited Product',
            'slug' => 'limited-product',
            'sku' => 'LIMIT-1',
            'price' => 100,
            'currency' => 'USD',
            'stock' => 1,
            'status' => 'active',
        ]);

        $service = app(InventoryService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock.');

        $service->adjustStock(
            productId: $product->id,
            variantId: null,
            type: 'remove',
            quantity: 2,
        );
    }
}
