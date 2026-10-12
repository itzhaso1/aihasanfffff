<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogInventoryPolicy;
use App\Enums\ChatCatalog\ChatCatalogUnavailableBehavior;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;

class ChatCatalogInventoryPresenter
{
    /**
     * @return array{available: bool, label: string, quantity: ?int, policy: string}
     */
    public function forItem(ChatCatalog $catalog, ChatCatalogItem $item): array
    {
        $policy = $item->inventory_policy ?? ChatCatalogInventoryPolicy::FiniteStock;
        $behavior = $catalog->unavailable_item_behavior ?? ChatCatalogUnavailableBehavior::ShowUnavailable;

        return $this->present($policy, $item->stock, $behavior);
    }

    /**
     * @return array{available: bool, label: string, quantity: ?int, policy: string}
     */
    public function forVariant(ChatCatalog $catalog, ChatCatalogItemVariant $variant): array
    {
        $policy = $variant->inventory_policy ?? ChatCatalogInventoryPolicy::FiniteStock;
        $behavior = $catalog->unavailable_item_behavior ?? ChatCatalogUnavailableBehavior::ShowUnavailable;

        return $this->present($policy, $variant->stock, $behavior);
    }

    /**
     * @return array{available: bool, label: string, quantity: ?int, policy: string}
     */
    private function present(
        ChatCatalogInventoryPolicy $policy,
        ?int $stock,
        ChatCatalogUnavailableBehavior $behavior,
    ): array {
        if (in_array($policy, [
            ChatCatalogInventoryPolicy::DigitalPool,
            ChatCatalogInventoryPolicy::FileAsset,
        ], true)) {
            return [
                'available' => true,
                'label' => 'available',
                'quantity' => null,
                'policy' => ChatCatalogInventoryPolicy::NotTracked->value,
            ];
        }

        return match ($policy) {
            ChatCatalogInventoryPolicy::NotTracked,
            ChatCatalogInventoryPolicy::Unlimited,
            ChatCatalogInventoryPolicy::OnRequest => [
                'available' => true,
                'label' => 'available',
                'quantity' => null,
                'policy' => $policy->value,
            ],
            ChatCatalogInventoryPolicy::FiniteStock => $this->finiteStock($stock, $behavior, $policy),
            ChatCatalogInventoryPolicy::Backorder => $this->finiteStock($stock, $behavior, $policy, allowBackorder: true),
            default => $this->finiteStock($stock, $behavior, $policy),
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
}
