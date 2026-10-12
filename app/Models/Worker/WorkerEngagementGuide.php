<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\WorkspaceScopedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'worker_engagement_id',
    'company_overview',
    'products_context',
    'responsibilities',
    'workflow_steps',
    'important_rules',
    'customer_guidance',
    'escalation_rules',
    'work_schedule_notes',
    'important_notes',
    'faq_items',
])]
class WorkerEngagementGuide extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return [
            'responsibilities' => 'array',
            'workflow_steps' => 'array',
            'important_rules' => 'array',
            'customer_guidance' => 'array',
            'escalation_rules' => 'array',
            'faq_items' => 'array',
        ];
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(WorkerEngagement::class, 'worker_engagement_id');
    }

    public function hasContent(): bool
    {
        foreach ([
            $this->company_overview,
            $this->products_context,
            $this->work_schedule_notes,
            $this->important_notes,
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        foreach ([
            $this->responsibilities,
            $this->workflow_steps,
            $this->important_rules,
            $this->customer_guidance,
            $this->escalation_rules,
            $this->faq_items,
        ] as $items) {
            if (is_array($items) && $items !== []) {
                return true;
            }
        }

        return false;
    }
}
