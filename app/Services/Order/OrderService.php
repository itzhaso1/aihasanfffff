<?php

namespace App\Services\Order;

use App\Events\OrderCreated;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Customer\CustomerAggregateService;
use App\Services\Inventory\InventoryService;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceOrderNumberGenerator $orderNumberGenerator,
        private readonly CustomerAggregateService $customerAggregateService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Order
    {
        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($data, $actor): Order {
                    return $this->createWithinTransaction($data, $actor);
                });
            } catch (QueryException $exception) {
                if (! $this->isRetryableConcurrencyFailure($exception) || $attempt >= 4) {
                    throw $exception;
                }

                $attempt++;
                usleep(random_int(10_000, 80_000));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createWithinTransaction(array $data, ?User $actor): Order
    {
        return (function () use ($data, $actor): Order {
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

            $lineDrafts = [];
            $subtotal = '0.00';

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
                $unitPrice = $product->sale_price ?: $product->price;

                if (! empty($item['product_variant_id'])) {
                    $variant = ProductVariant::withoutGlobalScopes()
                        ->where('workspace_id', $workspaceId)
                        ->where('product_id', $product->id)
                        ->whereKey((int) $item['product_variant_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $unitPrice = $variant->sale_price ?: $variant->price ?: $unitPrice;
                }

                $quantity = (int) $item['quantity'];
                if ($quantity <= 0) {
                    throw new RuntimeException('Quantity must be at least 1.');
                }
                $lineDiscount = Money::round(max(0, (string) ($item['discount_amount'] ?? 0)));
                $lineTotal = Money::sub(
                    Money::quantityTimesUnitPrice($quantity, $unitPrice),
                    $lineDiscount,
                );
                if (Money::cmp($lineTotal, '0') < 0) {
                    $lineTotal = '0.00';
                }
                $subtotal = Money::add($subtotal, $lineTotal);

                $lineDrafts[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => Money::round($unitPrice),
                    'discount_amount' => $lineDiscount,
                    'total_amount' => $lineTotal,
                ];
            }

            $order = Order::query()->create([
                'workspace_id' => $workspaceId,
                'customer_id' => $customerId,
                'dining_table_id' => $data['dining_table_id'] ?? null,
                'table_session_id' => $data['table_session_id'] ?? null,
                'finance_invoice_id' => $data['finance_invoice_id'] ?? null,
                'order_number' => $this->orderNumberGenerator->nextCommerceNumber($workspaceId),
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

            foreach ($lineDrafts as $lineDraft) {
                $product = $lineDraft['product'];
                $variant = $lineDraft['variant'];

                $order->items()->create([
                    'workspace_id' => $workspaceId,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'variant_name' => $variant?->name,
                    'sku' => $variant?->sku ?? $product->sku,
                    'quantity' => $lineDraft['quantity'],
                    'unit_price' => $lineDraft['unit_price'],
                    'discount_amount' => $lineDraft['discount_amount'],
                    'total_amount' => $lineDraft['total_amount'],
                ]);

                $this->inventoryService->adjustStock(
                    productId: $product->id,
                    variantId: $variant?->id,
                    type: 'reserve',
                    quantity: $lineDraft['quantity'],
                    actor: $actor,
                    referenceType: 'order',
                    referenceId: $order->id,
                    notes: 'Order reservation'
                );
            }

            $total = Money::add(
                Money::sub($subtotal, Money::round((string) $order->discount_amount)),
                Money::round((string) $order->shipping_amount),
            );
            if (Money::cmp($total, '0') < 0) {
                $total = '0.00';
            }
            $order->update([
                'subtotal' => $subtotal,
                'total_amount' => $total,
            ]);

            if ($customerId && $order->placed_at) {
                $this->customerAggregateService->incrementOrderAggregates(
                    $workspaceId,
                    $customerId,
                    (string) $order->total_amount,
                    $order->placed_at,
                );
            }

            event(new OrderCreated($order));

            return $order->fresh(['items', 'customer', 'table', 'tableSession']);
        })();
    }

    private function isRetryableConcurrencyFailure(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['40001', 'HY000'], true)
            || in_array($driverCode, [1213, 1205], true);
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

            if ($order->customer_id) {
                $this->customerAggregateService->refreshOrderAggregates(
                    (int) $order->workspace_id,
                    (int) $order->customer_id,
                );
            }
        });

        return $order->fresh(['items', 'customer']);
    }
}
