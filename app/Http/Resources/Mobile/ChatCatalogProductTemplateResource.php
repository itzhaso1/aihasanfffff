<?php

namespace App\Http\Resources\Mobile;

use App\Models\ChatCatalogProductTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatCatalogProductTemplate */
class ChatCatalogProductTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'classification_hint' => $this->classification_hint,
            'status' => $this->status?->value ?? $this->status,
            'schema_version' => (int) $this->schema_version,
            'is_system' => (bool) $this->is_system,
            'source_template_id' => $this->source_template_id,
            'fields' => ChatCatalogProductTemplateFieldResource::collection(
                $this->whenLoaded('fields', fn () => $this->fields, collect()),
            ),
        ];
    }
}
