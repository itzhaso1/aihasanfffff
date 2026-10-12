<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\Worker\WorkerDispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkerDispute */
class WorkerDisputeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status;
        $reporter = $this->reporter_type;

        return [
            'id' => $this->id,
            'worker_engagement_id' => $this->worker_engagement_id,
            'reporter_type' => $reporter instanceof \BackedEnum ? $reporter->value : $reporter,
            'category' => $this->category,
            'description' => $this->description,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'resolution_summary' => $this->resolution_summary,
            'opened_at' => optional($this->opened_at)?->toIso8601String(),
            'under_review_at' => optional($this->under_review_at)?->toIso8601String(),
            'resolved_at' => optional($this->resolved_at)?->toIso8601String(),
            'closed_at' => optional($this->closed_at)?->toIso8601String(),
        ];
    }
}
