<?php

namespace App\Models\Commerce;

use App\Enums\Commerce\CommerceOperatingMode;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceScopedModel;
use Database\Factories\Commerce\CommerceWorkspaceProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'operating_mode',
    'metadata',
])]
class CommerceWorkspaceProfile extends WorkspaceScopedModel
{
    /** @use HasFactory<CommerceWorkspaceProfileFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'operating_mode' => CommerceOperatingMode::class,
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
