<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile\Worker;

use App\Models\Worker\WorkerIdentityPrivate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkerIdentityPrivate */
class WorkerIdentityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'legal_name' => $this->legal_name,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'identity_number' => $this->identity_number,
            'address' => $this->address,
        ];
    }
}
