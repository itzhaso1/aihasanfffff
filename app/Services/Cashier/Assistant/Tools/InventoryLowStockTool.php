<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\Product;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Cashier\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Cashier\Assistant\Contracts\CashierAssistantTool;
use Illuminate\Support\Facades\Validator;

final class InventoryLowStockTool implements CashierAssistantTool
{
    use BuildsAssistantFacts;

    private const THRESHOLD = 5;

    public function name(): string
    {
        return 'inventory.low_stock';
    }

    public function description(): string
    {
        return 'Products at or below low-stock threshold.';
    }

    public function permission(): string
    {
        return 'inventory.manage';
    }

    public function parameterSchema(): array
    {
        return [
            'limit' => 'optional integer 1-50',
        ];
    }

    public function validateArguments(array $arguments): array
    {
        return Validator::validate($arguments, [
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $limit = (int) ($arguments['limit'] ?? 20);
        $today = now()->toDateString();

        $products = Product::query()
            ->where('inventory_tracking', true)
            ->where('status', 'active')
            ->where('stock', '<=', self::THRESHOLD)
            ->orderBy('stock')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'stock']);

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'products',
            $today,
            $today,
            [
                'available' => true,
                'threshold' => self::THRESHOLD,
                'count' => $products->count(),
                'items' => $products->map(fn (Product $product): array => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => (int) $product->stock,
                ])->values()->all(),
            ],
        ));
    }
}
