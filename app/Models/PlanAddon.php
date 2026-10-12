<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'description',
    'meter_key',
    'quantity',
    'price',
    'currency',
    'billing_period',
    'grants',
    'is_active',
])]
class PlanAddon extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'grants' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function workspaceAddons(): HasMany
    {
        return $this->hasMany(WorkspaceAddon::class);
    }
}
