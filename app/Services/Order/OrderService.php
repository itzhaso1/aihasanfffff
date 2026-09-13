<?php

namespace App\Services\Order;

use App\Events\OrderCreated;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($data, $actor): Order {
            $items = $data['items'] ?? [];
            if (! is_array($items) || $items === []) {
                throw new \InvalidArgumentException('Order requires at least one item.');
            }

            $workspaceId = (int) ($data['workspace_id'] ?? $this->workspaceContext->workspaceId() ?? 0);
            if ($workspaceId <= 0) {
                throw new RuntimeException('Workspace context is required to create orders.');
            }

            $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
            if ($customerId) {
                $customerExists = Customer::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($customerId)
                    ->exists();

                if (! $customerExists) {
                    throw new RuntimeException('Customer does not belong to current workspace.');
                }
            }

            $order = Order::query()->create([
                'workspace_id' => $workspaceId,
                'customer_id' => $customerId,
                'dining_table_id' => $data['dining_table_id'] ?? null,
                'table_session_id' => $data['table_session_id'] ?? null,
                'finance_invoice_id' => $data['finance_invoice_id'] ?? null,
                'order_number' => $this->nextOrderNumber(),
                'source' => $data['source'] ?? 'manual',
                'status' => $data['status'] ?? 'confirmed',
                'pos_status' => $data['pos_status'] ?? 'new',
                'payment_status' => 'pending',
                'fulfillment_status' => 'unfulfilled',
                'shipping_status' => 'not_shipped',
                'currency' => $data['currency'] ?? 'USD',
                'discount_amount' => $data['discount_amount'] ?? 0,
                'shipping_amount' => $data['shipping_amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
                'placed_at' => now(),
            ]);

            $subtotal = 0.0;

            foreach ($items as $item) {
                $product = Product::withoutGlobalScopes()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey((int) $item['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($product->status !== 'active') {
                    throw new RuntimeException('Only active products can be added to orders.');
                }

                $variant = null;
                $unitPrice = (float) ($product->sale_price ?: $product->price);

                if (! empty($item['product_variant_id'])) {
                    $variant = ProductVariant::withoutGlobalScopes()
                        ->where('workspace_id', $workspaceId)
                        ->where('product_id', $product->id)
                        ->whereKey((int) $item['product_variant_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $unitPrice = (float) ($variant->sale_price ?: $variant->price ?: $unitPrice);
                }

                $quantity = (int) $item['quantity'];
                if ($quantity <= 0) {
                    throw new RuntimeException('Quantity must be at least 1.');
                }
                $lineDiscount = max(0, (float) ($item['discount_amount'] ?? 0));
                $lineTotal = max(0, ($unitPrice * $quantity) - $lineDiscount);
                $subtotal += $lineTotal;

                $order->items()->create([
                    'workspace_id' => $workspaceId,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'variant_name' => $variant?->name,
                    'sku' => $variant?->sku ?? $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $lineDiscount,
                    'total_amount' => $lineTotal,
                ]);

                $this->inventoryService->adjustStock(
                    productId: $product->id,
                    variantId: $variant?->id,
                    type: 'reserve',
                    quantity: $quantity,
                    actor: $actor,
                    referenceType: 'order',
                    referenceId: $order->id,
                    notes: 'Order reservation'
                );
            }

            $total = $subtotal - (float) $order->discount_amount + (float) $order->shipping_amount;
            $order->update([
                'subtotal' => $subtotal,
                'total_amount' => max(0, $total),
            ]);

            if ($order->customer) {
                $order->customer->update([
                    'orders_count' => $order->customer->orders()->count(),
                    'total_purchases' => $order->customer->orders()->sum('total_amount'),
                    'last_order_at' => now(),
                ]);
            }

            event(new OrderCreated($order));

            return $order->fresh(['items', 'customer', 'table', 'tableSession']);
        });
    }

    public function cancel(Order $order, ?User $actor = null): Order
    {
        if ($order->status === 'cancelled') {
            return $order;
        }

        DB::transaction(function () use ($order, $actor): void {
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $this->inventoryService->adjustStock(
                    productId: $item->product_id,
                    variantId: $item->product_variant_id,
                    type: 'release',
                    quantity: (int) $item->quantity,
                    actor: $actor,
                    referenceType: 'order_cancelled',
                    referenceId: $order->id,
                    notes: 'Order cancellation release'
                );
            }

            $order->update([
                'status' => 'cancelled',
                'pos_status' => 'cancelled',
                'fulfillment_status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        });

        return $order->fresh(['items', 'customer']);
    }

    private function nextOrderNumber(): string
    {
        $lastId = (Order::withoutGlobalScopes()->max('id') ?? 0) + 1;

        return 'ORD-'.str_pad((string) $lastId, 8, '0', STR_PAD_LEFT);
    }
}
