<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\Worker\WorkerEngagementGuide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkerEngagementGuide */
class WorkerEngagementGuideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_overview' => $this->company_overview,
            'products_context' => $this->products_context,
            'responsibilities' => array_values($this->responsibilities ?? []),
            'workflow_steps' => array_values($this->workflow_steps ?? []),
            'important_rules' => array_values($this->important_rules ?? []),
            'customer_guidance' => array_values($this->customer_guidance ?? []),
            'escalation_rules' => array_values($this->escalation_rules ?? []),
            'work_schedule_notes' => $this->work_schedule_notes,
            'important_notes' => $this->important_notes,
            'faq_items' => collect($this->faq_items ?? [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $item): array => [
                    'question' => (string) ($item['question'] ?? ''),
                    'answer' => (string) ($item['answer'] ?? ''),
                ])
                ->values()
                ->all(),
        ];
    }
}
