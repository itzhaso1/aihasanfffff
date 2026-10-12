<?php

namespace App\Http\Resources\Mobile;

use App\Models\ChatCatalogDigitalInventoryPool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatCatalogDigitalInventoryPool */
class ChatCatalogDigitalPoolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chat_catalog_item_id' => $this->chat_catalog_item_id,
            'chat_catalog_item_variant_id' => $this->chat_catalog_item_variant_id,
            'name' => $this->name,
            'low_stock_threshold' => $this->low_stock_threshold,
            'reservation_timeout_seconds' => $this->reservation_timeout_seconds,
            'available_units_count' => $this->whenCounted('availableUnits'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
