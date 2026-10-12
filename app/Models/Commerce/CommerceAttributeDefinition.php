<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceAttributeDataType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'key',
    'display_name',
    'description',
    'data_type',
    'unit',
    'is_required',
    'is_searchable',
    'is_filterable',
    'is_customer_visible',
    'is_ai_readable',
    'validation_constraints',
    'enum_options',
    'is_active',
    'sort_order',
])]
class CommerceAttributeDefinition extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_attribute_definitions';

    protected function casts(): array
    {
        return [
            'data_type' => CommerceAttributeDataType::class,
            'is_required' => 'boolean',
            'is_searchable' => 'boolean',
            'is_filterable' => 'boolean',
            'is_customer_visible' => 'boolean',
            'is_ai_readable' => 'boolean',
            'validation_constraints' => 'array',
            'enum_options' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function entityValues(): HasMany
    {
        return $this->hasMany(CommerceEntityAttributeValue::class, 'commerce_attribute_definition_id');
    }
}
