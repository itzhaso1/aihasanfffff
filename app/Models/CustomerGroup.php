<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'workspace_id',
    'name',
    'description',
])]
class CustomerGroup extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_group_customer')
            ->withTimestamps();
    }
}
