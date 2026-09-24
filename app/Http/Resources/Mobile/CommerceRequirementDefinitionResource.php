<?php

namespace App\Http\Resources\Mobile;

use App\Models\Commerce\CommerceRequirementDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommerceRequirementDefinition */
class CommerceRequirementDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'data_type' => $this->data_type?->value ?? $this->data_type,
            'unit' => $this->unit,
            'is_required' => (bool) $this->is_required,
            'validation_rules' => $this->validation_rules ?? [],
            'applicability' => $this->applicability ?? [],
            'is_active' => (bool) $this->is_active,
        ];
    }
}
