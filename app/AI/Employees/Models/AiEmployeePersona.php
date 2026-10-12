<?php

declare(strict_types=1);

namespace App\AI\Employees\Models;

use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'ai_employee_id',
    'language',
    'dialect',
    'formality',
    'tone',
    'emoji_policy',
    'greeting_policy',
    'traits',
    'style_notes',
])]
class AiEmployeePersona extends WorkspaceScopedModel
{
    protected $table = 'ai_employee_personas';

    protected function casts(): array
    {
        return ['traits' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class, 'ai_employee_id');
    }
}
