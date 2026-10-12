<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkerEngagement */
class WorkerEngagementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status;
        $durationUnit = $this->duration_unit;

        return [
            'id' => $this->id,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'job_title' => $this->job_title,
            'job_description' => $this->job_description,
            'starts_at' => optional($this->starts_at)?->toDateString(),
            'ends_at' => optional($this->ends_at)?->toDateString(),
            'duration_value' => $this->duration_value,
            'duration_unit' => $durationUnit instanceof \BackedEnum
                ? $durationUnit->value
                : $durationUnit,
            'offered_contract_amount_minor' => $this->offered_contract_amount_minor,
            'offered_contract_currency' => $this->offered_contract_currency,
            'agreed_contract_amount_minor' => $this->agreed_contract_amount_minor,
            'agreed_contract_currency' => $this->agreed_contract_currency,
            'agreed_contract_at' => optional($this->agreed_contract_at)?->toIso8601String(),
            'guide' => $this->when(
                $this->relationLoaded('guide')
                    && $status instanceof WorkerEngagementStatus
                    && $status->allowsWorkerGuideVisibility(),
                fn () => $this->guide
                    ? new WorkerEngagementGuideResource($this->guide)
                    : null,
            ),
            'workspace' => $this->whenLoaded('workspace', fn () => $this->workspace ? [
                'id' => $this->workspace->id,
                'name' => $this->workspace->name,
            ] : null),
            'capabilities' => $this->relationLoaded('permissions')
                ? $this->permissions
                    ->filter(fn (WorkerEngagementPermission $permission): bool => $permission->allowed)
                    ->map(fn (WorkerEngagementPermission $permission): array => [
                        'capability_key' => $permission->capability_key,
                        'display_name' => $permission->capabilityDefinition?->display_name
                            ?: $permission->capability_key,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
