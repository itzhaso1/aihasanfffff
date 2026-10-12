<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceAttributeDataType;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'workspace_id',
    'key',
    'label',
    'description',
    'data_type',
    'unit',
    'is_required',
    'validation_rules',
    'applicability',
    'is_active',
])]
class CommerceRequirementDefinition extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_requirement_definitions';

    protected function casts(): array
    {
        return [
            'data_type' => CommerceAttributeDataType::class,
            'is_required' => 'boolean',
            'validation_rules' => 'array',
            'applicability' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
