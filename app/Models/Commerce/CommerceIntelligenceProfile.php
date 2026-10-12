<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceDomain;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'workspace_id',
    'primary_domain',
    'secondary_domains',
    'enabled_capabilities',
    'metadata',
])]
class CommerceIntelligenceProfile extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_intelligence_profiles';

    protected function casts(): array
    {
        return [
            'primary_domain' => CommerceDomain::class,
            'secondary_domains' => 'array',
            'enabled_capabilities' => 'array',
            'metadata' => 'array',
        ];
    }
}
