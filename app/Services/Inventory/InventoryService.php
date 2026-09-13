<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function adjustStock(
        int $productId,
        ?int $variantId,
        string $type,
        int $quantity,
        ?User $actor = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than 0.');
        }

        return DB::transaction(function () use ($productId, $variantId, $type, $quantity, $actor, $referenceType, $referenceId, $notes): InventoryMovement {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if (! $product) {
                throw new ModelNotFoundException('Product not found.');
            }

            $variant = null;
            if ($variantId) {
                $variant = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->whereKey($variantId)
                    ->lockForUpdate()
                    ->first();

                if (! $variant) {
                    throw new ModelNotFoundException('Product variant not found.');
                }
            }

            $targetStock = $variant ? $variant->stock : $product->stock;
            $before = $targetStock;
            $after = $this->calculateAfterQuantity($type, $before, $quantity);

            if ($variant) {
                $variant->update(['stock' => $after]);
            } else {
                $product->update(['stock' => $after]);
            }

            return InventoryMovement::query()->create([
                'workspace_id' => $product->workspace_id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'type' => $type,
                'quantity' => $quantity,
                'before_quantity' => $before,
                'after_quantity' => $after,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => $actor?->id,
                'notes' => $notes,
            ]);
        });
    }

    private function calculateAfterQuantity(string $type, int $before, int $quantity): int
    {
        return match ($type) {
            'add', 'return', 'release' => $before + $quantity,
            'remove', 'reserve' => $this->guardSufficientStock($before, $quantity),
            'adjustment' => $quantity,
            default => throw new \InvalidArgumentException('Unsupported inventory movement type.'),
        };
    }

    private function guardSufficientStock(int $before, int $quantity): int
    {
        if ($before < $quantity) {
            throw new \RuntimeException('Insufficient stock.');
        }

        return $before - $quantity;
    }
}
