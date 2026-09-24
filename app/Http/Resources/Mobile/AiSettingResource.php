<?php

namespace App\Http\Resources\Mobile;

use App\Models\AiSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AiSetting */
class AiSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ai_role' => $this->ai_role,
            'instructions' => $this->instructions,
            'tone' => $this->tone,
            'reply_style' => $this->reply_style,
            'rules' => $this->rules ?? [],
            'business_information' => $this->business_information ?? [],
            'agent_persona' => $this->agent_persona ?? [],
            'provider' => $this->provider,
            'model' => $this->model,
            'max_tokens' => (int) $this->max_tokens,
            'temperature' => $this->temperature !== null ? (float) $this->temperature : null,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
