<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'branch_id',
    'provider_id',
    'service_id',
    'starts_at',
    'ends_at',
    'reason',
    'created_by_user_id',
])]
class BookingBlockedSlot extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'provider_id' => 'integer',
            'service_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'created_by_user_id' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BookingBranch::class, 'branch_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BookingProvider::class, 'provider_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookingService::class, 'service_id');
    }
}
