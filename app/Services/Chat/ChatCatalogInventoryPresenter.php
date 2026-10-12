<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogUnavailableBehavior;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;

class ChatCatalogInventoryPresenter
{
    public function __construct(
        private readonly ChatCatalogDigitalInventoryService $digitalInventory,
    ) {}

    /**
     * @return array{available: bool, label: string, quantity: ?int, policy: string}
     */
    public function forItem(ChatCatalog $catalog, ChatCatalogItem $item): array
    {
        $policy = $item->inventory_policy ?? ChatCatalogInventoryPolicy::FiniteStock;
        $behavior = $catalog->unavailable_item_behavior ?? ChatCatalogUnavailableBehavior::ShowUnavailable;

        return match ($policy) {
            ChatCatalogInventoryPolicy::NotTracked,
            ChatCatalogInventoryPolicy::Unlimited,
            ChatCatalogInventoryPolicy::OnRequest => [
                'available' => true,
                'label' => 'available',
                'quantity' => null,
                'policy' => $policy->value,
            ],
            ChatCatalogInventoryPolicy::DigitalPool => $this->digitalPoolAvailability($item, null, $behavior),
            ChatCatalogInventoryPolicy::FiniteStock => $this->finiteStock($item->stock, $behavior, $policy),
            ChatCatalogInventoryPolicy::Backorder => $this->finiteStock($item->stock, $behavior, $policy, allowBackorder: true),
            default => $this->finiteStock($item->stock, $behavior, $policy),
        };
    }

    /**
     * @return array{available: bool, label: string, quantity: ?int, policy: string}
     */
    public function forVariant(ChatCatalog $catalog, ChatCatalogItemVariant $variant): array
    {
        $policy = $variant->inventory_policy ?? ChatCatalogInventoryPolicy::FiniteStock;
        $behavior = $catalog->unavailable_item_behavior ?? ChatCatalogUnavailableBehavior::ShowUnavailable;

        return match ($policy) {
            ChatCatalogInventoryPolicy::DigitalPool => $this->digitalPoolAvailability($variant->item, $variant, $behavior),
            ChatCatalogInventoryPolicy::FiniteStock => $this->finiteStock($variant->stock, $behavior, $policy),
            ChatCatalogInventoryPolicy::Unlimited,
            ChatCatalogInventoryPolicy::NotTracked => [
                'available' => true,
                'label' => 'available',
                'quantity' => null,
                'policy' => $policy->value,
            ],
            default => $this->finiteStock($variant->stock, $behavior, $policy),
        };
    }

    /**
     * @return array{available: bool, label: string, quantity: ?int, policy: string}
     */
    private function finiteStock(
        ?int $stock,
        ChatCatalogUnavailableBehavior $behavior,
        ChatCatalogInventoryPolicy $policy,
        bool $allowBackorder = false,
    ): array {
        $qty = $stock;
        $available = $qty === null || $qty > 0 || $allowBackorder;
        $label = $available
            ? ($allowBackorder && $qty !== null && $qty <= 0 ? 'backorder' : 'available')
            : $behavior->value;

        return [
            'available' => $available,
            'label' => $label,
            'quantity' => $qty,
            'policy' => $policy->value,
        ];
    }

    /**
     * @return array{available: bool, label: string, quantity: ?int, policy: string}
     */
    private function digitalPoolAvailability(
        ChatCatalogItem $item,
        ?ChatCatalogItemVariant $variant,
        ChatCatalogUnavailableBehavior $behavior,
    ): array {
        $pool = $item->relationLoaded('digitalPools')
            ? $item->digitalPools->first(fn (ChatCatalogDigitalInventoryPool $candidate): bool => $variant
                ? (int) $candidate->chat_catalog_item_variant_id === (int) $variant->id
                : $candidate->chat_catalog_item_variant_id === null)
            : null;
        if (! $pool) {
            $poolQuery = ChatCatalogDigitalInventoryPool::withoutGlobalScopes()
                ->where('workspace_id', $item->workspace_id)
                ->where('chat_catalog_item_id', $item->id);

            if ($variant) {
                $poolQuery->where('chat_catalog_item_variant_id', $variant->id);
            }
            $pool = $poolQuery->first();
        }
        if (! $pool) {
            return [
                'available' => false,
                'label' => $behavior->value,
                'quantity' => 0,
                'policy' => ChatCatalogInventoryPolicy::DigitalPool->value,
            ];
        }

        $availableCount = $pool->getAttribute('available_units_count') !== null
            ? (int) $pool->getAttribute('available_units_count')
            : $this->digitalInventory->countAvailableUnits($pool);
        $lowStock = $availableCount > 0 && $availableCount <= $pool->low_stock_threshold;

        return [
            'available' => $availableCount > 0,
            'label' => $availableCount > 0 ? ($lowStock ? 'low_stock' : 'available') : $behavior->value,
            'quantity' => $availableCount,
            'policy' => ChatCatalogInventoryPolicy::DigitalPool->value,
        ];
    }
}
