<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'branch_id',
    'user_id',
    'name',
    'role_label',
    'capacity_per_slot',
    'service_ids',
    'working_hours_override',
    'is_active',
    'sort_order',
])]
class BookingProvider extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'user_id' => 'integer',
            'capacity_per_slot' => 'integer',
            'service_ids' => 'array',
            'working_hours_override' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BookingBranch::class, 'branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'provider_id');
    }

    public function supportsService(?int $serviceId): bool
    {
        if ($serviceId === null || ! is_array($this->service_ids) || $this->service_ids === []) {
            return true;
        }

        $allowed = array_map('intval', $this->service_ids);

        return in_array($serviceId, $allowed, true);
    }
}
