<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/** @mixin PersonalAccessToken */
class DeviceSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentTokenId = $request->user()?->currentAccessToken()?->id;

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities,
            'last_used_at' => optional($this->last_used_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'expires_at' => optional($this->expires_at)?->toIso8601String(),
            'is_current' => $currentTokenId !== null && (int) $this->id === (int) $currentTokenId,
        ];

        $attributes = $this->resource->getAttributes();
        if (array_key_exists('device_name', $attributes)) {
            $data['device_name'] = $this->device_name;
            $data['device_type'] = $this->device_type;
            $data['user_agent'] = $this->user_agent;
            $data['ip_address'] = $this->ip_address;
        }

        if (array_key_exists('workspace_id', $attributes)) {
            $data['workspace_id'] = $this->workspace_id;
        }

        return $data;
    }
}
