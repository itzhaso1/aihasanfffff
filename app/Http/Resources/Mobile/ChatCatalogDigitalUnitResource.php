<?php

namespace App\Http\Resources\Mobile;

use App\Models\ChatCatalogDigitalInventoryUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatCatalogDigitalInventoryUnit */
class ChatCatalogDigitalUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chat_catalog_digital_inventory_pool_id' => $this->chat_catalog_digital_inventory_pool_id,
            'unit_type' => $this->unit_type?->value,
            'status' => $this->status?->value,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'metadata' => $this->safeMetadata(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Never expose credential/code payloads or private storage addresses.
     *
     * @return array<string, mixed>
     */
    private function safeMetadata(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return collect($metadata)
            ->only(['filename', 'mime_type', 'byte_size', 'checksum', 'version', 'label'])
            ->all();
    }
}
