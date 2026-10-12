<?php

declare(strict_types=1);

namespace App\Models\Worker;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'skill_key',
    'display_name',
    'sort_order',
])]
class WorkerSkill extends Model
{
    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(WorkerProfile::class, 'worker_profile_skills')
            ->withTimestamps();
    }
}
