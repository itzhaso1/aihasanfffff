<?php

declare(strict_types=1);

namespace App\Models\Worker;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'capability_key',
    'display_name',
    'description',
    'is_active',
    'sort_order',
])]
class WorkforceCapabilityDefinition extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(WorkerEngagementPermission::class, 'capability_key', 'capability_key');
    }
}
