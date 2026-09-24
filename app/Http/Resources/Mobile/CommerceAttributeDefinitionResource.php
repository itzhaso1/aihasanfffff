<?php

namespace App\Http\Resources\Mobile;

use App\Models\Commerce\CommerceAttributeDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommerceAttributeDefinition */
class CommerceAttributeDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'data_type' => $this->data_type?->value ?? $this->data_type,
            'unit' => $this->unit,
            'is_required' => (bool) $this->is_required,
            'is_searchable' => (bool) $this->is_searchable,
            'is_filterable' => (bool) $this->is_filterable,
            'is_customer_visible' => (bool) $this->is_customer_visible,
            'is_ai_readable' => (bool) $this->is_ai_readable,
            'validation_constraints' => $this->validation_constraints ?? [],
            'enum_options' => $this->enum_options ?? [],
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
