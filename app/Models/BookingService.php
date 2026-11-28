<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'slug',
    'category',
    'description',
    'duration_minutes',
    'capacity_per_slot',
    'price_minor',
    'currency',
    'requires_party_size',
    'max_party_size',
    'custom_fields',
    'is_active',
    'sort_order',
])]
class BookingService extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'capacity_per_slot' => 'integer',
            'price_minor' => 'integer',
            'requires_party_size' => 'boolean',
            'max_party_size' => 'integer',
            'custom_fields' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'service_id');
    }

    public function effectiveDurationMinutes(BookingSetting $setting): int
    {
        $minutes = (int) ($this->duration_minutes ?: $setting->default_duration_minutes ?: 30);

        return max(5, min(720, $minutes));
    }

    public function effectiveCapacity(BookingSetting $setting): int
    {
        $capacity = (int) ($this->capacity_per_slot ?: $setting->default_capacity_per_slot ?: 1);

        return max(1, $capacity);
    }

    public function formattedPrice(): ?string
    {
        if ($this->price_minor === null) {
            return null;
        }

        return Money::fromMinor((int) $this->price_minor);
    }
}
