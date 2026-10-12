<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'workspace_id',
    'meter_key',
    'period_start',
    'period_end',
    'used',
    'metadata',
])]
class WorkspaceUsageMeter extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'used' => 'decimal:4',
            'metadata' => 'array',
        ];
    }
}
