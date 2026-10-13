<?php

namespace App\Services\Commerce;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\Commerce\CommerceCartStatus;
use App\Enums\Commerce\CommerceReservationStatus;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Commerce\CommerceCart;
use App\Models\Commerce\CommerceCartItem;
use App\Models\Commerce\CommerceInventoryReservation;
use App\Models\Commerce\Seller;
use App\Models\Customer;
use App\Models\Workspace;
use App\Services\Chat\CatalogAvailabilityService;
use InvalidArgumentException;

/**
 * Validates the authoritative current catalog state before an order is made.
 *
 * Cart snapshots are presentation aids only. This service never accepts an
 * item price, seller, or workspace identity from a cart client.
 */
final class CommerceCartValidationService
{
    public function __construct(
        private readonly CommercePricingService $pricing,
        private readonly CatalogAvailabilityService $availability,
    ) {}

    public function validate(
        Workspace $workspace,
        CommerceCart $cart,
        bool $lockCatalogRows = false,
    ): CommerceCartValidationResult {
        $problems = [];
        if ((int) $cart->workspace_id !== (int) $workspace->id) {
            return new CommerceCartValidationResult([], [[
                'cart_item_id' => null,
                'code' => 'workspace_mismatch',
                'message' => 'Cart does not belong to the current workspace.',
            ]]);
        }

        if ($cart->isExpired()) {
            if ($cart->status === CommerceCartStatus::Active) {
                $cart->update(['status' => CommerceCartStatus::Expired]);
            }

            return new CommerceCartValidationResult([], [[
                'cart_item_id' => null,
                'code' => 'cart_expired',
                'message' => 'Cart has expired.',
            ]]);
        }

        if ($cart->status !== CommerceCartStatus::Active) {
            return new CommerceCartValidationResult([], [[
                'cart_item_id' => null,
                'code' => 'cart_not_active',
                'message' => 'Cart is not active.',
            ]]);
        }

        if ($cart->customer_id !== null && ! Customer::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($cart->customer_id)
            ->exists()) {
            return new CommerceCartValidationResult([], [[
                'cart_item_id' => null,
                'code' => 'customer_invalid',
                'message' => 'Customer does not belong to the current workspace.',
            ]]);
        }

        $catalog = ChatCatalog::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();
        if (! $catalog) {
            return new CommerceCartValidationResult([], [[
                'cart_item_id' => null,
                'code' => 'catalog_missing',
                'message' => 'Commerce catalog is not configured.',
            ]]);
        }

        $channel = strtolower(trim((string) $cart->channel));
        if (! in_array($channel, ['chat', 'whatsapp', 'instagram', 'web', 'marketplace'], true)
            || ! $catalog->channelEnabled($channel)) {
            return new CommerceCartValidationResult([], [[
                'cart_item_id' => null,
                'code' => 'channel_unavailable',
                'message' => 'Catalog is not available in this channel.',
            ]]);
        }
        if (! $this->availability->isCatalogOpenForCustomers($catalog)) {
            return new CommerceCartValidationResult([], [[
                'cart_item_id' => null,
                'code' => 'catalog_unavailable',
                'message' => 'Catalog is currently unavailable for ordering.',
            ]]);
        }

        $cartItems = $cart->relationLoaded('items')
            ? $cart->items
            : $cart->items()->orderBy('id')->get();
        if ($cartItems->isEmpty()) {
            return new CommerceCartValidationResult([], [[
                'cart_item_id' => null,
                'code' => 'cart_empty',
                'message' => 'Cart requires at least one item.',
            ]]);
        }

        $lines = [];
        foreach ($cartItems as $cartItem) {
            $line = $this->validateItem(
                workspace: $workspace,
                catalog: $catalog,
                cart: $cart,
                cartItem: $cartItem,
                lockCatalogRows: $lockCatalogRows,
            );
            if (isset($line['problem'])) {
                $problems[] = $line['problem'];

                continue;
            }

            $lines[] = $line['line'];
        }

        return new CommerceCartValidationResult($lines, $problems);
    }

    /**
     * @return array{line?:CommerceValidatedCartLine,problem?:array{cart_item_id:int|null,code:string,message:string}}
     */
    private function validateItem(
        Workspace $workspace,
        ChatCatalog $catalog,
        CommerceCart $cart,
        CommerceCartItem $cartItem,
        bool $lockCatalogRows,
    ): array {
        if ($cartItem->quantity < 1) {
            return $this->problem($cartItem, 'quantity_invalid', 'Quantity must be at least 1.');
        }

        $itemQuery = ChatCatalogItem::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($cartItem->chat_catalog_item_id);
        if ($lockCatalogRows) {
            $itemQuery->lockForUpdate();
        }
        $item = $itemQuery->first();
        if (! $item) {
            return $this->problem($cartItem, 'item_missing', 'Catalog item is unavailable.');
        }
        if (! $this->isPurchasableItem($item)) {
            return $this->problem($cartItem, 'item_unavailable', 'Catalog item is not published for purchase.');
        }

        if ($cartItem->chat_catalog_item_variant_id === null
            && $this->hasSellableVariant($workspace, $item)) {
            return $this->problem(
                $cartItem,
                'variant_required',
                'يجب اختيار نسخة المنتج قبل إضافته إلى السلة.',
            );
        }

        $variant = null;
        if ($cartItem->chat_catalog_item_variant_id !== null) {
            $variantQuery = ChatCatalogItemVariant::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('chat_catalog_item_id', $item->id)
                ->whereKey($cartItem->chat_catalog_item_variant_id);
            if ($lockCatalogRows) {
                $variantQuery->lockForUpdate();
            }
            $variant = $variantQuery->first();
            if (! $variant) {
                return $this->problem($cartItem, 'variant_missing', 'Catalog variant is unavailable.');
            }
            if (! $this->isSellableVariant($variant)) {
                return $this->problem($cartItem, 'variant_unavailable', 'Catalog variant is not published for purchase.');
            }
        }

        $maximum = $this->maximumQuantity($catalog, $item);
        if ($cartItem->quantity > $maximum) {
            return $this->problem(
                $cartItem,
                'quantity_exceeds_limit',
                "Quantity exceeds the maximum allowed for this item ({$maximum}).",
            );
        }

        $sellerId = $item->commerce_seller_id ?: $catalog->commerce_seller_id;
        if (! $sellerId) {
            return $this->problem($cartItem, 'seller_missing', 'Catalog item has no orderable seller.');
        }
        $seller = Seller::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($sellerId)
            ->where('status', 'active')
            ->first();
        if (! $seller) {
            return $this->problem($cartItem, 'seller_unavailable', 'Catalog item seller is unavailable.');
        }
        if (($cart->commerce_seller_id !== null && (int) $cart->commerce_seller_id !== (int) $seller->id)
            || ($cartItem->commerce_seller_id !== null && (int) $cartItem->commerce_seller_id !== (int) $seller->id)) {
            return $this->problem($cartItem, 'seller_mismatch', 'Cart seller context no longer matches the catalog item.');
        }

        try {
            $price = $this->pricing->resolve(
                $workspace,
                $catalog,
                $item,
                $variant,
                (int) $cartItem->quantity,
                $cart->currency,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->problem($cartItem, 'pricing_invalid', $exception->getMessage());
        }

        $policy = $variant?->inventory_policy ?? $item->inventory_policy ?? ChatCatalogInventoryPolicy::FiniteStock;
        $policyValue = $policy instanceof ChatCatalogInventoryPolicy ? $policy->value : (string) $policy;
        $inventoryProblem = $this->validateInventory(
            workspace: $workspace,
            item: $item,
            variant: $variant,
            policy: $policyValue,
            quantity: (int) $cartItem->quantity,
        );
        if ($inventoryProblem !== null) {
            return $this->problem($cartItem, $inventoryProblem['code'], $inventoryProblem['message']);
        }

        return [
            'line' => new CommerceValidatedCartLine(
                cartItem: $cartItem,
                item: $item,
                variant: $variant,
                seller: $seller,
                price: $price,
                inventoryPolicy: $policyValue,
            ),
        ];
    }

    private function isPurchasableItem(ChatCatalogItem $item): bool
    {
        return (bool) $item->is_active
            && (bool) $item->is_visible
            && $item->publication_status === 'published'
            && ($item->published_at === null || ! $item->published_at->isFuture())
            && $item->archived_at === null;
    }

    private function hasSellableVariant(Workspace $workspace, ChatCatalogItem $item): bool
    {
        return ChatCatalogItemVariant::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('chat_catalog_item_id', $item->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_visible', true)
            ->where('status', 'published')
            ->exists();
    }

    private function isSellableVariant(ChatCatalogItemVariant $variant): bool
    {
        return (bool) $variant->is_active
            && (bool) $variant->is_visible
            && $variant->status === 'published';
    }

    private function maximumQuantity(ChatCatalog $catalog, ChatCatalogItem $item): int
    {
        $candidateLimits = [
            data_get($catalog->customer_service_policy, 'max_order_quantity'),
            data_get($item->policies, 'max_order_quantity'),
        ];
        $limits = collect($candidateLimits)
            ->filter(fn ($limit): bool => is_numeric($limit) && (int) $limit > 0)
            ->map(fn ($limit): int => min(10_000, (int) $limit))
            ->values();

        return $limits->isEmpty() ? 100 : (int) $limits->min();
    }

    /**
     * @return array{code:string,message:string}|null
     */
    private function validateInventory(
        Workspace $workspace,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        string $policy,
        int $quantity,
    ): ?array {
        if ($policy === ChatCatalogInventoryPolicy::FiniteStock->value
            || $policy === ChatCatalogInventoryPolicy::ServiceCapacity->value) {
            $stock = $variant?->stock ?? $item->stock;
            if ($stock !== null) {
                $reserved = $this->reservedPhysicalQuantity($workspace, $item, $variant);
                if ($quantity > max(0, (int) $stock - $reserved)) {
                    return [
                        'code' => 'inventory_insufficient',
                        'message' => 'Requested quantity is no longer available.',
                    ];
                }
            }
        }

        if ($item->classification?->value === 'digital'
            || $policy === ChatCatalogInventoryPolicy::DigitalPool->value
            || $policy === ChatCatalogInventoryPolicy::FileAsset->value) {
            return [
                'code' => 'digital_products_disabled',
                'message' => 'Digital product selling and delivery are disabled.',
            ];
        }

        if ($policy === ChatCatalogInventoryPolicy::ServiceCapacity->value
            && $item->classification?->value !== 'service') {
            return [
                'code' => 'service_policy_invalid',
                'message' => 'Service capacity policy requires a service catalog item.',
            ];
        }

        return null;
    }

    private function reservedPhysicalQuantity(
        Workspace $workspace,
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
    ): int {
        return (int) CommerceInventoryReservation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('reservation_kind', 'physical')
            ->where('status', CommerceReservationStatus::Reserved->value)
            ->where('chat_catalog_item_id', $item->id)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->when(
                $variant,
                fn ($query) => $query->where('chat_catalog_item_variant_id', $variant->id),
                fn ($query) => $query->whereNull('chat_catalog_item_variant_id'),
            )
            ->sum('quantity');
    }

    /**
     * @return array{problem:array{cart_item_id:int|null,code:string,message:string}}
     */
    private function problem(CommerceCartItem $item, string $code, string $message): array
    {
        return [
            'problem' => [
                'cart_item_id' => (int) $item->id,
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
