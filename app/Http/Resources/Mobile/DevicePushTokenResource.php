<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DevicePushToken */
class DevicePushTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'platform' => $this->platform,
            'device_name' => $this->device_name,
            'workspace_id' => $this->workspace_id,
            'last_seen_at' => optional($this->last_seen_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'is_active' => $this->isActive(),
        ];
    }
}
