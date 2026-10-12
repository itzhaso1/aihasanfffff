<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'workspace_id',
    'name',
    'color',
])]
class CustomerTag extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_tag_customer')
            ->withTimestamps();
    }
}
