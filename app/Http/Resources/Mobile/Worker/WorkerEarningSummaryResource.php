<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\Worker\WorkerEarningLine;
use App\Models\Worker\WorkerEarningPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkerEarningPeriod */
class WorkerEarningSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status;
        $totals = [];
        $lines = [];

        if ($this->relationLoaded('earningLines')) {
            foreach ($this->earningLines as $line) {
                $currency = (string) $line->currency;
                $totals[$currency] = ($totals[$currency] ?? 0) + (int) $line->amount_minor;
                $lines[] = $this->linePayload($line);
            }
        }

        return [
            'id' => $this->id,
            'worker_engagement_id' => $this->worker_engagement_id,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'starts_at' => optional($this->starts_at)?->toDateString(),
            'ends_at' => optional($this->ends_at)?->toDateString(),
            'totals_minor' => $totals,
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linePayload(WorkerEarningLine $line): array
    {
        $lineType = $line->line_type;
        $sourceType = $line->source_type;

        return [
            'id' => $line->id,
            'line_type' => $lineType instanceof \BackedEnum ? $lineType->value : $lineType,
            'source_type' => $sourceType instanceof \BackedEnum ? $sourceType->value : $sourceType,
            'source_id' => $line->source_id,
            'amount_minor' => (int) $line->amount_minor,
            'currency' => $line->currency,
            'description' => $line->description,
        ];
    }
}
