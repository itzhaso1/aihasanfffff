<?php

namespace App\Http\Resources\Mobile;

use App\Models\Commerce\CommerceCart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommerceCart */
class CommerceCartResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'currency' => $this->currency,
            'status' => $this->status?->value ?? $this->status,
            'channel' => $this->channel,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'converted_order_id' => $this->converted_order_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'chat_catalog_item_id' => $item->chat_catalog_item_id,
                'chat_catalog_item_variant_id' => $item->chat_catalog_item_variant_id,
                'seller_id' => $item->commerce_seller_id,
                'quantity' => (int) $item->quantity,
                'unit_price' => $item->unit_price,
                'currency' => $item->currency,
                'price' => $item->price_snapshot ?? [],
                'selected_options' => $item->selected_options ?? [],
            ])->values()->all()),
        ];
    }
}
