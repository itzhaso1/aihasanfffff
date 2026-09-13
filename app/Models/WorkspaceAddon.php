<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'plan_addon_id',
    'status',
    'quantity',
    'starts_at',
    'ends_at',
    'metadata',
])]
class WorkspaceAddon extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(PlanAddon::class, 'plan_addon_id');
    }
}
