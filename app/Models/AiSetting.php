<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'name',
    'instructions',
    'tone',
    'reply_style',
    'rules',
    'business_information',
    'provider',
    'model',
    'max_tokens',
    'temperature',
    'is_active',
])]
class AiSetting extends WorkspaceScopedModel
{
    /** @use HasFactory<\Database\Factories\AiSettingFactory> */
    use BelongsToWorkspace, HasFactory;

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'business_information' => 'array',
            'temperature' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AiLog::class, 'workspace_id', 'workspace_id');
    }
}
