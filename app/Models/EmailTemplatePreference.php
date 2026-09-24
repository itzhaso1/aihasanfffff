<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\EmailTemplatePreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'template_key',
    'settings',
])]
class EmailTemplatePreference extends WorkspaceScopedModel
{
    /** @use HasFactory<EmailTemplatePreferenceFactory> */
    use BelongsToWorkspace, HasFactory;

    protected static function newFactory(): EmailTemplatePreferenceFactory
    {
        return EmailTemplatePreferenceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
