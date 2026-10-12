<?php

declare(strict_types=1);

namespace App\AI\Employees\Models;

use App\AI\Employees\EmployeeResponseLength;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * Employee model settings. Independent of ai_settings.provider/model/temperature, which
 * remain the source for Finance, POS and Cashier assistants.
 */
#[Fillable([
    'workspace_id',
    'ai_employee_id',
    'provider',
    'model',
    'temperature',
    'response_length',
    'max_output_tokens',
    'thinking_budget',
])]
class AiEmployeeModelSettings extends WorkspaceScopedModel
{
    protected $table = 'ai_employee_model_settings';

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'response_length' => EmployeeResponseLength::class,
            'max_output_tokens' => 'integer',
            'thinking_budget' => 'integer',
        ];
    }

    public function effectiveMaxOutputTokens(): int
    {
        return $this->max_output_tokens ?? $this->response_length->defaultMaxOutputTokens();
    }
}
