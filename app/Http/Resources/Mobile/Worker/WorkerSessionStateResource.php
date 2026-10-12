<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\Worker\WorkerWorkSession;
use App\Models\Worker\WorkerWorkSessionSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkerWorkSession */
class WorkerSessionStateResource extends JsonResource
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
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'started_at' => optional($this->started_at)?->toIso8601String(),
            'ended_at' => optional($this->ended_at)?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'segments' => $this->relationLoaded('segments')
                ? $this->segments
                    ->sortBy([
                        ['started_at', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->values()
                    ->map(fn (WorkerWorkSessionSegment $segment): array => [
                        'id' => $segment->id,
                        'segment_type' => $segment->segment_type instanceof \BackedEnum
                            ? $segment->segment_type->value
                            : $segment->segment_type,
                        'started_at' => optional($segment->started_at)?->toIso8601String(),
                        'ended_at' => optional($segment->ended_at)?->toIso8601String(),
                    ])
                    ->all()
                : [],
        ];
    }
}
