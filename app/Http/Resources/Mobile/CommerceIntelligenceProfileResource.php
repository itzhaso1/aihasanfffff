<?php

namespace App\Http\Resources\Mobile;

use App\Models\Commerce\CommerceIntelligenceProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommerceIntelligenceProfile */
class CommerceIntelligenceProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'primary_domain' => $this->primary_domain?->value ?? $this->primary_domain,
            'secondary_domains' => $this->secondary_domains ?? [],
            'enabled_capabilities' => $this->enabled_capabilities ?? [],
            'metadata' => $this->metadata ?? [],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
