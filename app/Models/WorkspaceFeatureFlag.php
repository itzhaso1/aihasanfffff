<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Feature\FeatureAccessService;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'workspace_id',
    'feature_key',
    'enabled',
    'source',
    'constraints',
])]
class WorkspaceFeatureFlag extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        $forget = function (WorkspaceFeatureFlag $flag): void {
            app(FeatureAccessService::class)->forgetWorkspaceFeatureCache((int) $flag->workspace_id);
        };

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'constraints' => 'array',
        ];
    }
}
