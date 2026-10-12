<?php

declare(strict_types=1);

namespace App\Http\Resources\Workforce;

use App\Services\Workforce\WorkforceParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkforceParticipant */
class WorkforceParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $participant = $this->resource;

        if (! $participant instanceof WorkforceParticipant) {
            return [];
        }

        return array_intersect_key(
            $participant->toArray(),
            array_flip(WorkforceParticipant::ALLOWED_KEYS),
        );
    }
}
