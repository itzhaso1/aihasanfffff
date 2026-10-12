<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'email',
    'normalized_email',
    'phone',
    'company',
    'job_title',
    'notes',
    'is_favorite',
    'avatar_path',
])]
class EmailContact extends WorkspaceScopedModel
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_favorite' => 'boolean',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            EmailContactGroup::class,
            'email_contact_group_contact',
            'email_contact_id',
            'email_contact_group_id',
        )->withTimestamps();
    }
}
