<?php

declare(strict_types=1);

namespace App\AI\Employees\Models;

use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable([
    'workspace_id',
    'ai_employee_id',
    'conversation_memory_enabled',
    'customer_memory_enabled',
    'commerce_context_enabled',
    'relevant_history_enabled',
])]
class AiEmployeeMemorySettings extends WorkspaceScopedModel
{
    protected $table = 'ai_employee_memory_settings';

    protected function casts(): array
    {
        return [
            'conversation_memory_enabled' => 'boolean',
            'customer_memory_enabled' => 'boolean',
            'commerce_context_enabled' => 'boolean',
            'relevant_history_enabled' => 'boolean',
        ];
    }
}
