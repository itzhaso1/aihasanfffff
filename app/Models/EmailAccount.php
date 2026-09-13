<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'workspace_id',
    'name',
    'email',
    'password',
    'imap_host',
    'imap_port',
    'smtp_host',
    'smtp_port',
    'logo_path',
    'brand_color',
    'aliases',
])]
class EmailAccount extends WorkspaceScopedModel
{
    use BelongsToWorkspace, SoftDeletes;

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'aliases' => 'array',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }
}
