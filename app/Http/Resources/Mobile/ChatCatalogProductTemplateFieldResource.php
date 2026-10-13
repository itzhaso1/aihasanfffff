<?php

namespace App\Http\Resources\Mobile;

use App\Models\ChatCatalogProductTemplateField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChatCatalogProductTemplateField */
class ChatCatalogProductTemplateFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->field_key,
            'field_key' => $this->field_key,
            'label' => $this->label ?? [],
            'type' => $this->field_type?->value ?? $this->field_type,
            'field_type' => $this->field_type?->value ?? $this->field_type,
            'level' => $this->level?->value ?? $this->level,
            'required' => (bool) $this->required,
            'searchable' => (bool) $this->searchable,
            'filterable' => (bool) $this->filterable,
            'customer_visible' => (bool) $this->customer_visible,
            'options' => $this->options,
            'validation' => $this->validation,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
