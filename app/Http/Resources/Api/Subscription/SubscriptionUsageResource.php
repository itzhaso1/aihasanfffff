<?php

namespace App\Http\Resources\Api\Subscription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'features' => $payload['features'] ?? [],
            'limits' => $payload['limits'] ?? [],
            'meters' => $payload['meters'] ?? [],
            'subscription_status' => $payload['subscription_status'] ?? null,
        ];
    }
}
