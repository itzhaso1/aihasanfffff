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
class EmailContactGroup extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            EmailContact::class,
            'email_contact_group_contact',
            'email_contact_group_id',
            'email_contact_id',
        )->withTimestamps();
    }
}
