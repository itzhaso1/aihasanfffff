<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;
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

        if (Schema::hasColumn('personal_access_tokens', 'device_name')) {
            $data['device_name'] = $this->device_name;
            $data['device_type'] = $this->device_type;
            $data['user_agent'] = $this->user_agent;
            $data['ip_address'] = $this->ip_address;
        }

        if (Schema::hasColumn('personal_access_tokens', 'workspace_id')) {
            $data['workspace_id'] = $this->workspace_id;
        }

        return $data;
    }
}
