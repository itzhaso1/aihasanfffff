<?php

namespace App\Models;

use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldLevel;
use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_template_id',
    'field_key',
    'label',
    'field_type',
    'level',
    'required',
    'searchable',
    'filterable',
    'customer_visible',
    'options',
    'validation',
    'visibility_rules',
    'sort_order',
])]
class ChatCatalogProductTemplateField extends Model
{
    protected function casts(): array
    {
        return [
            'label' => 'array',
            'field_type' => ChatCatalogProductTemplateFieldType::class,
            'level' => ChatCatalogProductTemplateFieldLevel::class,
            'required' => 'boolean',
            'searchable' => 'boolean',
            'filterable' => 'boolean',
            'customer_visible' => 'boolean',
            'options' => 'array',
            'validation' => 'array',
            'visibility_rules' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogProductTemplate::class, 'product_template_id');
    }

    public function mapsToItemColumn(): ?string
    {
        return match ($this->field_key) {
            'brand' => 'brand',
            'price' => 'price',
            default => null,
        };
    }
}
