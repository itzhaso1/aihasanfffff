<?php

namespace App\Services\Commerce;

use App\Enums\Commerce\CommerceCartStatus;
use App\Enums\Commerce\CommerceOrderStatus;
use App\Events\CommerceOrderCancelled;
use App\Events\CommerceOrderCreated;
use App\Events\CommerceOrderValidated;
use App\Events\CommerceSellerAllocationCreated;
use App\Exceptions\CommerceCartValidationException;
use App\Models\Commerce\CommerceCart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Order\WorkspaceOrderNumberGenerator;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Canonical creation service for Chat Catalog Commerce orders.
 *
 * It intentionally does not use the legacy OrderService: that service is a
 * Finance Product + InventoryService flow, while Commerce lines snapshot
 * Chat Catalog identities and reserve their own authoritative inventory.
 */
final class CommerceOrderService
{
    public function __construct(
        private readonly CommerceCartValidationService $validator,
        private readonly CommerceInventoryReservationService $reservations,
        private readonly SellerAllocationService $allocations,
        private readonly WorkspaceOrderNumberGenerator $orderNumbers,
        private readonly AuditLogService $audit,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function validateCartForOrder(
        Workspace $workspace,
        CommerceCart $cart,
    ): CommerceCartValidationResult {
        $result = $this->workspaceContext->runFor($workspace, function () use ($workspace, $cart): CommerceCartValidationResult {
            $fresh = CommerceCart::query()->with('items')->whereKey($cart->id)->firstOrFail();
            $this->assertCartWorkspace($workspace, $fresh);

            return $this->validator->validate($workspace, $fresh);
        });

        if ($result->isValid()) {
            event(new CommerceOrderValidated($cart));
        }

        return $result;
    }

    public function createDraftFromCart(
        Workspace $workspace,
        CommerceCart $cart,
        string $idempotencyKey,
        ?User $actor = null,
    ): Order {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('A valid conversion idempotency key is required.');
        }

        $created = false;
        $order = $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $cart,
            $idempotencyKey,
            &$created,
        ): Order {
            return DB::transaction(function () use ($workspace, $cart, $idempotencyKey, &$created): Order {
                $lockedCart = CommerceCart::query()
                    ->with('items')
                    ->whereKey($cart->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCartWorkspace($workspace, $lockedCart);

                if ($lockedCart->status === CommerceCartStatus::Converted && $lockedCart->converted_order_id) {
                    return $this->loadCommerceOrder($workspace, (int) $lockedCart->converted_order_id);
                }
                if ($lockedCart->isExpired()) {
                    $lockedCart->update(['status' => CommerceCartStatus::Expired]);
                    throw new RuntimeException('Cart has expired.');
                }
                if ($lockedCart->status !== CommerceCartStatus::Active) {
                    throw new RuntimeException('Cart is not active.');
                }

                $matchingKeyCart = CommerceCart::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('conversion_idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($matchingKeyCart && (int) $matchingKeyCart->id !== (int) $lockedCart->id) {
                    if ($matchingKeyCart->converted_order_id) {
                        return $this->loadCommerceOrder($workspace, (int) $matchingKeyCart->converted_order_id);
                    }
                    throw new RuntimeException('Conversion idempotency key is already in use.');
                }

                $validation = $this->validator->validate($workspace, $lockedCart, true);
                if (! $validation->isValid()) {
                    throw new CommerceCartValidationException($validation->problems);
                }
                event(new CommerceOrderValidated($lockedCart));

                $sellerIds = collect($validation->lines)
                    ->map(fn (CommerceValidatedCartLine $line): int => (int) $line->seller->id)
                    ->unique()
                    ->values();
                $order = Order::query()->create([
                    'workspace_id' => $workspace->id,
                    'customer_id' => $lockedCart->customer_id,
                    'commerce_seller_id' => $sellerIds->count() === 1 ? $sellerIds->first() : null,
                    'commerce_cart_id' => $lockedCart->id,
                    'order_number' => $this->orderNumbers->nextCommerceNumber((int) $workspace->id),
                    'source' => 'commerce',
                    'order_type' => 'commerce',
                    // Keep the existing enum-compatible status for shared
                    // orders; the explicit Commerce state is the canonical
                    // customer-payment lifecycle.
                    'status' => 'draft',
                    'commerce_status' => CommerceOrderStatus::Draft,
                    'commerce_channel' => $lockedCart->channel,
                    'pos_status' => 'new',
                    'payment_status' => 'pending',
                    'fulfillment_status' => 'unfulfilled',
                    'shipping_status' => 'not_shipped',
                    'currency' => $lockedCart->currency,
                    'subtotal' => 0,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'shipping_amount' => 0,
                    'total_amount' => 0,
                    'metadata' => [
                        'commerce' => [
                            'cart_id' => (int) $lockedCart->id,
                            'channel' => $lockedCart->channel,
                            'pricing_source' => 'chat_catalog',
                        ],
                    ],
                    'placed_at' => now(),
                ]);

                $totals = [
                    'subtotal' => Money::fromMinor(0),
                    'discount_amount' => Money::fromMinor(0),
                    'tax_amount' => Money::fromMinor(0),
                    'total_amount' => Money::fromMinor(0),
                    'rates' => [],
                ];

                foreach ($validation->lines as $line) {
                    $orderItem = $this->createOrderItem($order, $line);
                    $totals['subtotal'] = Money::add($totals['subtotal'], $line->price->subtotal);
                    $totals['tax_amount'] = Money::add($totals['tax_amount'], $line->price->taxAmount);
                    $totals['total_amount'] = Money::add($totals['total_amount'], $line->price->total);
                    if ($line->price->taxRate !== null) {
                        $totals['rates'][] = $line->price->taxRate;
                    }

                    $allocation = $this->allocations->createForOrderLine(
                        $order,
                        $line->seller,
                        [
                            'gross_amount' => $line->price->total,
                            'platform_fee_amount' => Money::fromMinor(0),
                            'fee_type' => 'none',
                            'fee_metadata' => ['strategy' => 'no_fee_configuration'],
                            'currency' => $line->price->currency,
                        ],
                        $orderItem,
                    );
                    event(new CommerceSellerAllocationCreated($allocation));

                    $this->reservations->reserveForOrderLine(
                        $workspace,
                        $order,
                        $orderItem,
                        $line->item,
                        $line->variant,
                        $line->inventoryPolicy,
                    );
                }

                $rates = collect($totals['rates'])->unique()->values();
                $order->update([
                    'subtotal' => $totals['subtotal'],
                    'discount_amount' => $totals['discount_amount'],
                    'tax_amount' => $totals['tax_amount'],
                    'tax_rate' => $rates->count() === 1 ? $rates->first() : null,
                    'shipping_amount' => Money::fromMinor(0),
                    'total_amount' => $totals['total_amount'],
                ]);

                $lockedCart->update([
                    'status' => CommerceCartStatus::Converted,
                    'converted_order_id' => $order->id,
                    'conversion_idempotency_key' => $idempotencyKey,
                ]);
                $created = true;

                return $this->loadCommerceOrder($workspace, (int) $order->id);
            });
        });

        if ($created) {
            $this->audit->log(
                action: 'commerce.order.created',
                entityType: Order::class,
                entityId: (int) $order->id,
                actor: $actor,
                meta: [
                    'cart_id' => $order->commerce_cart_id,
                    'currency' => $order->currency,
                    'total_amount' => $order->total_amount,
                    'line_count' => $order->items->count(),
                ],
                workspaceId: (int) $workspace->id,
            );
            event(new CommerceOrderCreated($order));
        }

        return $order;
    }

    public function createOrder(
        Workspace $workspace,
        CommerceCart $cart,
        string $idempotencyKey,
        ?User $actor = null,
    ): Order {
        $draft = $this->createDraftFromCart($workspace, $cart, $idempotencyKey, $actor);

        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $draft): Order {
            return DB::transaction(function () use ($workspace, $draft): Order {
                $locked = Order::query()
                    ->whereKey($draft->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCommerceOrder($workspace, $locked);

                if ($locked->commerce_status === CommerceOrderStatus::Draft) {
                    $locked->update(['commerce_status' => CommerceOrderStatus::PendingPayment]);
                }

                return $this->loadCommerceOrder($workspace, (int) $locked->id);
            });
        });
    }

    /**
     * Recalculates header sums from immutable line snapshots only.
     * It never rereads mutable catalog prices after an order has been created.
     */
    public function recalculateOrder(Workspace $workspace, Order $order, ?User $actor = null): Order
    {
        $updated = $this->workspaceContext->runFor($workspace, function () use ($workspace, $order): Order {
            return DB::transaction(function () use ($workspace, $order): Order {
                $locked = Order::query()
                    ->with('items')
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCommerceOrder($workspace, $locked);

                $subtotal = Money::fromMinor(0);
                $discount = Money::fromMinor(0);
                $tax = Money::fromMinor(0);
                $total = Money::fromMinor(0);
                $rates = [];
                foreach ($locked->items as $item) {
                    $subtotal = Money::add(
                        $subtotal,
                        data_get($item->price_snapshot, 'subtotal', $item->total_amount),
                    );
                    $discount = Money::add($discount, $item->discount_amount);
                    $tax = Money::add($tax, $item->tax_amount ?? 0);
                    $total = Money::add($total, $item->total_amount);
                    if ($item->tax_rate !== null) {
                        $rates[] = (string) $item->tax_rate;
                    }
                }

                $uniqueRates = collect($rates)->unique()->values();
                $locked->update([
                    'subtotal' => $subtotal,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                    'tax_rate' => $uniqueRates->count() === 1 ? $uniqueRates->first() : null,
                    'shipping_amount' => Money::fromMinor(0),
                    'total_amount' => $total,
                ]);

                return $this->loadCommerceOrder($workspace, (int) $locked->id);
            });
        });

        $this->audit->log(
            action: 'commerce.order.recalculated',
            entityType: Order::class,
            entityId: (int) $updated->id,
            actor: $actor,
            meta: ['total_amount' => $updated->total_amount],
            workspaceId: (int) $workspace->id,
        );

        return $updated;
    }

    public function cancelDraft(Workspace $workspace, Order $order, ?User $actor = null): Order
    {
        $cancelled = $this->workspaceContext->runFor($workspace, function () use ($workspace, $order): Order {
            return DB::transaction(function () use ($workspace, $order): Order {
                $locked = Order::query()
                    ->with(['items', 'inventoryReservations.digitalUnit'])
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCommerceOrder($workspace, $locked);

                if ($locked->commerce_status === CommerceOrderStatus::Cancelled) {
                    return $this->loadCommerceOrder($workspace, (int) $locked->id);
                }
                if ($locked->payment_status === 'paid') {
                    throw new RuntimeException('Paid Commerce orders cannot be cancelled in this phase.');
                }

                $this->reservations->releaseForOrder($locked);
                $locked->update([
                    'status' => 'cancelled',
                    'commerce_status' => CommerceOrderStatus::Cancelled,
                    'fulfillment_status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

                return $this->loadCommerceOrder($workspace, (int) $locked->id);
            });
        });

        $this->audit->log(
            action: 'commerce.order.cancelled',
            entityType: Order::class,
            entityId: (int) $cancelled->id,
            actor: $actor,
            workspaceId: (int) $workspace->id,
        );
        event(new CommerceOrderCancelled($cancelled));

        return $cancelled;
    }

    private function createOrderItem(Order $order, CommerceValidatedCartLine $line): OrderItem
    {
        $item = $line->item;
        $variant = $line->variant;
        $price = $line->price;
        $sellerName = $line->seller->display_name ?: $line->seller->name;
        $classification = $item->classification?->value;

        return $order->items()->create([
            'workspace_id' => $order->workspace_id,
            'order_id' => $order->id,
            'commerce_seller_id' => $line->seller->id,
            // This is a deliberately optional bridge. Chat Catalog identities
            // are primary; no Finance Product is fabricated or synchronized.
            'product_id' => null,
            'product_variant_id' => null,
            'pos_menu_item_id' => null,
            'chat_catalog_item_id' => $item->id,
            'chat_catalog_item_variant_id' => $variant?->id,
            'product_name' => $item->name,
            'variant_name' => $variant?->name,
            'item_type' => $item->item_kind ?: $classification,
            'sku' => $variant?->sku ?: $item->sku,
            'barcode' => $variant?->barcode ?: $item->barcode,
            'seller_display_name' => $sellerName,
            'currency' => $price->currency,
            'classification' => $classification,
            'fulfillment_classification' => $this->fulfillmentClassification($classification),
            'tax_presentation' => $price->taxPresentation,
            'quantity' => $price->quantity,
            'unit_price' => $price->unitPrice,
            'discount_amount' => $price->discountAmount,
            'taxable_amount' => $price->taxableAmount,
            'tax_rate' => $price->taxRate,
            'tax_amount' => $price->taxAmount,
            'total_amount' => $price->total,
            'catalog_snapshot' => [
                'item_name' => $item->name,
                'variant_name' => $variant?->name,
                'sku' => $variant?->sku ?: $item->sku,
                'barcode' => $variant?->barcode ?: $item->barcode,
                'customer_description' => $item->customer_description ?: $item->short_description,
                'classification' => $classification,
                'service_type' => $item->service_type,
                'digital_subtype' => $item->digital_subtype,
                'seller_display_name' => $sellerName,
            ],
            'price_snapshot' => $price->toSnapshot(),
            'tax_snapshot' => [
                'taxable_amount' => $price->taxableAmount,
                'tax_amount' => $price->taxAmount,
                'tax_rate' => $price->taxRate,
                'presentation' => $price->taxPresentation,
            ],
            'selected_options' => $line->cartItem->selected_options ?? [],
            'metadata' => [
                'inventory_policy' => $line->inventoryPolicy,
                'booking_required' => (bool) data_get($item->policies, 'booking_required', false),
                'provider_required' => (bool) data_get($item->policies, 'provider_required', false),
            ],
        ]);
    }

    private function fulfillmentClassification(?string $classification): string
    {
        return match ($classification) {
            'digital' => 'digital',
            'service' => 'service',
            default => 'physical',
        };
    }

    private function loadCommerceOrder(Workspace $workspace, int $orderId): Order
    {
        return Order::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('source', 'commerce')
            ->whereKey($orderId)
            ->with([
                'customer',
                'commerceCart',
                'items.commerceSeller',
                'sellerAllocations.seller',
                'inventoryReservations',
            ])
            ->firstOrFail();
    }

    private function assertCartWorkspace(Workspace $workspace, CommerceCart $cart): void
    {
        if ((int) $cart->workspace_id !== (int) $workspace->id) {
            abort(404);
        }
    }

    private function assertCommerceOrder(Workspace $workspace, Order $order): void
    {
        if ((int) $order->workspace_id !== (int) $workspace->id || $order->source !== 'commerce') {
            abort(404);
        }
    }
}
