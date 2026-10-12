<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\Worker\WorkerPayoutRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkerPayoutRecord */
class WorkerPayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status;

        return [
            'id' => $this->id,
            'worker_engagement_id' => $this->worker_engagement_id,
            'worker_earning_period_id' => $this->worker_earning_period_id,
            'amount_minor' => (int) $this->amount_minor,
            'currency' => $this->currency,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'requested_at' => optional($this->requested_at)?->toIso8601String(),
            'processing_at' => optional($this->processing_at)?->toIso8601String(),
            'paid_at' => optional($this->paid_at)?->toIso8601String(),
            'failed_at' => optional($this->failed_at)?->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
        ];
    }
}
