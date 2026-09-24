<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\AiSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'name',
    'ai_role',
    'instructions',
    'tone',
    'reply_style',
    'rules',
    'business_information',
    'agent_persona',
    'provider',
    'model',
    'max_tokens',
    'temperature',
    'is_active',
])]
class AiSetting extends WorkspaceScopedModel
{
    /** @use HasFactory<AiSettingFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'business_information' => 'array',
            'agent_persona' => 'array',
            'temperature' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AiLog::class, 'workspace_id', 'workspace_id');
    }
}
