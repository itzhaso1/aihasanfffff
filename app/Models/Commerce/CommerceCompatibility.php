<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceCompatibilityType;
use App\Enums\Commerce\CommerceProvenanceSource;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'workspace_id',
    'source_entity_type',
    'source_entity_id',
    'target_type',
    'target_entity_type',
    'target_entity_id',
    'target_reference',
    'compatibility_type',
    'constraints',
    'status',
    'provenance',
])]
class CommerceCompatibility extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_compatibilities';

    protected function casts(): array
    {
        return [
            'source_entity_id' => 'integer',
            'target_entity_id' => 'integer',
            'target_reference' => 'array',
            'compatibility_type' => CommerceCompatibilityType::class,
            'constraints' => 'array',
            'provenance' => CommerceProvenanceSource::class,
        ];
    }
}
