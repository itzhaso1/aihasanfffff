<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceRuleType;
use App\Models\ChatCatalogCategory;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'name',
    'rule_type',
    'target_entity_type',
    'target_entity_id',
    'category_id',
    'conditions',
    'actions',
    'priority',
    'is_active',
])]
class CommerceIntelligenceRule extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'commerce_intelligence_rules';

    protected function casts(): array
    {
        return [
            'rule_type' => CommerceRuleType::class,
            'target_entity_id' => 'integer',
            'category_id' => 'integer',
            'conditions' => 'array',
            'actions' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChatCatalogCategory::class, 'category_id');
    }
}
