<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'whats_app_account_id',
    'name',
    'language',
    'category',
    'status',
    'provider_template_id',
    'components',
    'metadata',
])]
class WhatsAppTemplate extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected $table = 'whatsapp_templates';

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whats_app_account_id');
    }
}
